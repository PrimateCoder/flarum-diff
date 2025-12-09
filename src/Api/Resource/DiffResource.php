<?php

namespace TheTurk\Diff\Api\Resource;

use Carbon\Carbon;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Post\PostRepository;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Jfcherng\Diff\Differ;
use Jfcherng\Diff\Factory\RendererFactory;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheTurk\Diff\Models\Diff;
use TheTurk\Diff\Repositories\DiffArchiveRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Tobyz\JsonApiServer\Context as OriginalContext;

/**
 * @extends Resource\AbstractDatabaseResource<Diff>
 */
class DiffResource extends Resource\AbstractDatabaseResource
{
    public function __construct(
        protected CommentPost $commentPost,
        protected DiffArchiveRepository $diffArchive,
        protected ExtensionManager $extensions,
        protected PostRepository $posts,
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator)
    {}

    public function type(): string
    {
        return 'diff';
    }

    public function model(): string
    {
        return Diff::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    public function results(object $query, Context $context): array
    {
        $postId = Arr::get($context->request->getQueryParams(), 'id');
        return $query.findWhere(
            ['post_id' => $postId],
            ['revision' => 'DESC']
        );
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Delete::make()
                ->authenticated()
                ->visible(function (Diff $diff, Context $context): bool {
                    $actor = $context->getActor();

                    if ($actor->can('deleteEditHistory'))
                    {
                        return true;
                    }

                    $post = $this->posts->findOrFail($diff->post_id, $actor);
                    $isSelf = $actor->id === $post->user_id;

                    if ($isSelf && $actor->can('selfDeleteEditHistory'))
                    {
                        return true;
                    }

                    return false;
                })
                ->action(function (Diff $diff, Context $context){
                    // if this is an archived revision
                    if ($diff->archive_id !== null) {
                        $this->diffArchive->deleteArchivedContent(
                            $diff->archive_id,
                            $diff->id
                        );
                        // it's not archived anymore
                        $diff->archive_id = null;
                    }

                    if ($diff->archive_id === null) {
                        $diff->content = null;
                    }
                    $diff->deleted_user_id = $actor->id;
                    $diff->deleted_at = Carbon::now();
                    $diff->save();
                }),
            Endpoint\Index::make()
                ->authenticated()
                ->can('viewEditHistory')
                ->defaultInclude(['actor', 'deletedUser', 'rolledbackUser'])
                ->paginate(),
        ];
    }

    public function fields(): array
    {
        $previewHtml = null;
        $inlineHtml = null;
        $sideBySideHtml = null;
        $combinedHtml = null;
        $comparisonBetween = null;

        if (null === $diff->deleted_at) {
            // get post's revision count
            $diffSubject = Diff::where('post_id', $diff->post_id);
            $revisionCount = $diffSubject->max('revision');

            $currentRevision = $diff->content;

            // get uncompressed revision content for comparison.
            if ($diff->archive_id !== null) {
                $currentRevision = $this->diffArchive->getArchivedContent(
                    $diff->archive_id,
                    $diff->id
                );
            }

            // we'll compare this current (new) revision
            // with one of the (old) previous revisions.
            // this array is very useful to give informations about
            // comparisons to users.
            $comparisonArray = [
                'new' => [
                    'revision' => $diff->revision,
                    'diffId'   => $diff->id,
                ],
            ];

            // we don't know anything about are there any previous revisions
            // to compare with current revision yet.
            $oldRevision = '';

            // if this is a last revision then we'll compare it with current content.
            // it's a bit confusing but remember - revisions starts with 0 (original content)
            // last revision is actually the current content.
            if ($diff->revision == $revisionCount && null === $currentRevision) {
                $currentRevision = Post::findOrFail($diff->post_id)->content;
            }

            // set html attribute for the preview mode.
            $previewHtml = $this->formatter($currentRevision);

            // find a revision to compare with current revision
            $compareWith = $diffSubject->where('revision', '<', $diff->revision)
                ->where('deleted_at', null)
                ->orderBy('revision', 'DESC')->first();

            // if current revision is the zeroth (original content)
            // or there are nothing to compare with latest revision
            // then switch to preview mode.
            // remember that latest revision is the current content
            if (
                $diff->revision == 0 ||
                ($diff->revision == $revisionCount && $compareWith === null)
            ) {
                // keep in mind that old and new will be equal in $comparisonArray
                // if this condition happens.
                $comparisonArray['old'] = [
                    'revision' => $diff->revision,
                    'diffId'   => $diff->id,
                ];
            } else {
                // if there are nothing to compare with,
                // then compare current revision with the current content
                // -1 indicates current content in $comparisonArray.
                if ($compareWith === null) {
                    $oldRevision = Post::findOrFail($diff->post_id)->content;
                    $comparisonArray['old'] = [
                        'revision' => -1,
                        'diffId'   => null,
                    ];
                } else {
                    if ($compareWith->archive_id !== null) {
                        // get uncompressed revision content for comparison.
                        $oldRevision = $this->diffArchive->getArchivedContent(
                            $compareWith->archive_id,
                            $compareWith->id
                        );
                    } else {
                        $oldRevision = $compareWith->content;
                    }

                    $comparisonArray['old'] = [
                        'revision' => $compareWith->revision,
                        'diffId'   => $compareWith->id,
                    ];
                }

                $ignoreCase = $ignoreWhiteSpace = false;

                // support for my 'the-turk/flarum-quiet-edits' extension
                if ($this->extensions->isEnabled('the-turk-quiet-edits')) {
                    $ignoreCase = $this->settings->get('the-turk-quiet-edits.ignoreCase', true);
                    $ignoreWhiteSpace = $this->settings->get('the-turk-quiet-edits.ignoreWhitespace', true);
                }

                // calculate differences between revisions
                // more options can be found at jfcherng's repo.
                $differ = new Differ(
                    explode("\n", $oldRevision),
                    explode("\n", $currentRevision),
                    [
                        // how many neighbor lines do we want to show?
                        'context' => (int)
                        $this->settings->get('the-turk-diff.neighborLines', 2),
                        // iGnoRe cAsE diFfErEnceS
                        'ignoreCase' => $ignoreCase,
                        // i g nore white spac e dif feren ces
                        'ignoreWhitespace' => $ignoreWhiteSpace,
                    ]
                );

                $rendererOptions = [
                    // line-level is the default level
                    'detailLevel' => $this->settings->get(
                        'the-turk-diff.detailLevel',
                        'line'
                    ),
                    // show a separator between different diff hunks in HTML renderers
                    'separateBlock' => (bool) $this->settings->get(
                        'the-turk-diff.separateBlock',
                        true
                    ),
                    'lineNumbers'    => false,
                    'wrapperClasses' => ['TheTurkDiff', 'CustomDiff', 'diff-wrapper'],
                    // shows when there are no differences found between revisions
                    'resultForIdenticals' => '<div class="noDiff"><p>'
                    .$this->translator->trans('the-turk-diff.forum.noDiff').
                    '</p></div>',
                    // this option is just for Combined renderer
                    'mergeThreshold' => \TheTurk\Diff\Jobs\ArchiveDiffs::sanitizeFloat($this->settings->get(
                        'the-turk-diff.mergeThreshold',
                        0.8
                    )),
                ];

                $inlineRenderer = RendererFactory::make('Inline', $rendererOptions);
                $inlineHtml = $inlineRenderer->render($differ);

                $sideBySideRenderer = RendererFactory::make('SideBySide', $rendererOptions);
                $sideBySideHtml = $sideBySideRenderer->render($differ);

                $combinedRenderer = RendererFactory::make('Combined', $rendererOptions);
                $combinedHtml = $combinedRenderer->render($differ);
            }

            $comparisonBetween = json_encode($comparisonArray);
        }

        return [

            Schema\Integer::make('revision'),
            Schema\DateTime::make('createdAt'),
            Scheme\DateTime::make('deletedAt'),
            Scheme\DateTime::make('rollbackedAt'),
            Schema\Boolean::make('canDeleteEditHistory'),
            Schema\Str::make('inlineHtml'),
            Schema\Str::make('sideBySideHtml'),
            Schema\Str::make('combinedHtml'),
            Schema\Str::make('previewHtml'),
            Schema\Str::make('comparisonBetween'),





            Schema\Relationship\ToOne::make('actor')
                ->includable()
                // ->inverse('?') // the inverse relationship name if any.
                ->type('actors'), // the serialized type of this relation (type of the relation model's API resource).
            Schema\Relationship\ToOne::make('deletedUser')
                ->includable()
                // ->inverse('?') // the inverse relationship name if any.
                ->type('deletedUsers'), // the serialized type of this relation (type of the relation model's API resource).
            Schema\Relationship\ToOne::make('rollbackedUser')
                ->includable()
                // ->inverse('?') // the inverse relationship name if any.
                ->type('rollbackedUsers'), // the serialized type of this relation (type of the relation model's API resource).
        ];
    }

    public function sorts(): array
    {
        return [
            // SortColumn::make('createdAt'),
        ];
    }

    /*
     * Render & parse the preview content.
     * I had to do this trick because new instance of
     * TextFormatter means fresh configuration.
     * I don't want to lose Flarum's configuration.
     *
     * @param string $content
     * @return string
     */
    public function formatter(string $content)
    {
        if ($this->settings->get('the-turk-diff.textFormatting', true)) {
            return $this->commentPost->getFormatter()->render(
                $this->commentPost->getFormatter()->parse(
                    $content,
                    $this->commentPost,
                    $this->getActor()
                )
            );
        }

        return \htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    }
}

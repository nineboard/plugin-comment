<?php

declare(strict_types=1);

namespace Xpressengine\Plugins\Comment\ToggleMenus\Comments;

use Xpressengine\Permission\Instance;
use Xpressengine\Plugins\Comment\Handler as CommentHandler;
use Xpressengine\Plugins\Comment\Models\Comment;
use Xpressengine\Plugins\Comment\Plugin as CommentPlugin;
use Xpressengine\ToggleMenu\AbstractToggleMenu;

/**
 * Class EditItem
 */
class EditItem extends AbstractToggleMenu
{
    /**
     * commentHandler
     *
     * @var CommentHandler
     */
    private $commentHandler;

    /**
     * UpdateItem constructor.
     */
    public function __construct(CommentHandler $commentHandler)
    {
        $this->commentHandler = $commentHandler;
    }

    /**
     * Delete Toggle Item's title
     */
    public static function getTitle(): string
    {
        return xe_trans('xe::update');
    }

    /**
     * Delete Toggle Item's text
     */
    public function getText(): string
    {
        return static::getTitle();
    }

    /**
     * getType
     */
    public function getType(): string
    {
        return static::MENUTYPE_EXEC;
    }

    /**
     * getAction
     */
    public function getAction(): string
    {
        return sprintf('CommentToggleMenu.update(event, "%s")', $this->identifier);
    }

    /**
     * getScript
     */
    public function getScript(): string
    {
        return CommentPlugin::asset('assets/js/toggleMenu.js');
    }

    /**
     * allows
     */
    public function allows(): bool
    {
        $comment = Comment::findOrFail($this->identifier);
        $permissionInstance = new Instance($this->commentHandler->getKeyForPerm($comment->instance_id));

        if (\Gate::allows('manage', $permissionInstance) === true) {
            return true;
        }

        return $comment->user_id === \Auth::id();
    }
}

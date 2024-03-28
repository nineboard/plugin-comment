<?php
/**
 * CommentUIObject.php
 *
 * PHP version 7
 *
 * @category    Comment
 *
 * @author      XE Developers <developers@xpressengine.com>
 * @copyright   2019 Copyright XEHub Corp. <https://www.xehub.io>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 *
 * @link        https://xpressengine.io
 */

namespace Xpressengine\Plugins\Comment;

use View;
use XeEditor;
use XeFrontend;
use XeSkin;
use Xpressengine\Plugins\Comment\Exceptions\InvalidArgumentException;
use Xpressengine\UIObject\AbstractUIObject;

/**
 * CommentUIObject
 *
 * @category    Comment
 *
 * @author      XE Developers <developers@xpressengine.com>
 * @copyright   2019 Copyright XEHub Corp. <https://www.xehub.io>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 *
 * @link        https://xpressengine.io
 */
class CommentUIObject extends AbstractUIObject
{
    /**
     * To do on boot
     *
     * @return void
     */
    public static function boot()
    {
        // nothing to do
    }

    /**
     * Rendering the view
     *
     * @return string
     *
     * @throws InvalidArgumentException
     */
    public function render()
    {
        /** @var CommentUsable $target */
        $target = $this->arguments['target'];

        if (! $target instanceof CommentUsable) {
            $e = new InvalidArgumentException;
            $e->setMessage(xe_trans('comment::InstanceMust', ['name' => 'CommentUsable::class']));

            throw $e;
        }

        $plugin = app('xe.plugin.comment');
        /** @var Handler $handler */
        $handler = $plugin->getHandler();
        $instanceId = $handler->getInstanceId($target->getInstanceId());

        $config = $handler->getConfig($instanceId);

        // 콘텐츠 폰트 설정 적용
        $editorConfig = XeEditor::getConfig($instanceId);
        $fontSize = $editorConfig->get('fontSize');
        $fontFamily = $editorConfig->get('fontFamily');

        $contentStyle = [];
        if ($fontSize) {
            $contentStyle[] = 'font-size: '.$fontSize.';';
        }
        if ($fontFamily) {
            $contentStyle[] = 'font-family: '.$fontFamily.';';
        }
        if ($contentStyle) {
            app('xe.frontend')->html('xe.content.style.'.$instanceId)->content('
                <style>
                    .xe-content-'.$instanceId.' {'.implode($contentStyle).'}
                </style>
            ')->appendTo('head')->load();
        }

        $this->loadDependencies();
        $this->initAssets();

        $props = [
            'config' => [
                'removeType' => $config->get('removeType'),
                'reverse' => $config->get('reverse'),
                'editor' => null,
            ],
        ];

        /** @var \Xpressengine\Editor\AbstractEditor $editor */
        if ($editor = XeEditor::get($instanceId)) {
            $editor->setArguments(false);

            $props['config']['editor'] = [
                'name' => $editor->getName(),
                'options' => $editor->getOptions(),
                'customOptions' => $editor->getCustomOptions(),
                'tools' => $editor->getTools(),
            ];

            $editorConfig = XeEditor::getConfig($instanceId);
            if ($editorConfig->getPure('height') == null) {
                $props['config']['editor']['options']['height'] = '160';
            }
        }

        $skin = XeSkin::getAssigned([$plugin->getId(), $instanceId]);
        $view = $skin->setView('container')->setData(compact('config'))->render();

        return view(sprintf('%s::views.uio', $plugin->getId()), [
            'instanceId' => $instanceId,
            'target' => $target,
            'editor' => $editor,
            'inner' => $view,
            'props' => $props,
        ]);
    }

    /**
     * load dependencies
     *
     * @return void
     */
    protected function loadDependencies()
    {
        XeFrontend::js('assets/core/xe-ui-component/js/xe-page.js')->load();
    }

    /**
     * init assets
     *
     * @return void
     */
    protected function initAssets()
    {
        XeFrontend::js('plugins/comment/assets/js/service.js')->appendTo('head')->load();

        XeFrontend::translation(['xe::autoSave', 'xe::confirmDelete', 'comment::msgRemoveUnable', 'comment::removeContent']);
    }
}

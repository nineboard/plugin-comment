<?php
/**
 * This file is comment ui object class
 *
 * @author      XE Developers (jhyeon1010) <cjh1010@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER Crop. <http://www.navercorp.com>
 * @license     LGPL-2.1
 * @license     http://www.gnu.org/licenses/old-licenses/lgpl-2.1.html
 * @link        https://xpressengine.io
 */

namespace Xpressengine\Plugins\Comment;

use Xpressengine\UIObject\AbstractUIObject;
use View;
use XeFrontend;
use XeSkin;
use XeEditor;
use Xpressengine\Plugins\Comment\Exceptions\InvalidArgumentException;

/**
 * 댓글 ui object 랜더링
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
     * @throws InvalidArgumentException
     */
    public function render()
    {
        /** @var CommentUsable $target */
        $target = $this->arguments['target'];

        if (!$target instanceof CommentUsable) {
            $e = new InvalidArgumentException;
            $e->setMessage(xe_trans('comment::InstanceMust', ['name' => 'CommentUsable::class']));

            throw $e;
        }

        $plugin = app('xe.plugin.comment');
        /** @var Handler $handler */
        $handler = $plugin->getHandler();
        $instanceId = $handler->getInstanceId($target->getInstanceId());

        $config = $handler->getConfig($instanceId);

        $this->loadDependencies();
        $this->initAssets();

        $props = [
            'config' => [
                'reverse' => $config->get('reverse'),
                'editor' => null,
            ]
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
        }

        $skin = XeSkin::getAssigned($plugin->getId());
        $view = $skin->setView('container')->setData(compact('config'))->render();

        return view(sprintf('%s::views.uio', $plugin->getId()), [
            'instanceId' => $instanceId,
            'target' => $target,
            'editor' => $editor,
            'inner' => $view,
            'props' => $props
        ]);


    }

    protected function loadDependencies()
    {
        XeFrontend::js('/assets/core/xe-ui-component/js/xe-page.js')->load();
    }

    protected function initAssets()
    {
        XeFrontend::css('plugins/comment/assets/css/comment.css')->load();
        XeFrontend::js('plugins/comment/assets/js/service.js')->appendTo('head')->load();

        XeFrontend::translation(['xe::autoSave', 'xe::tempSave', 'comment::msgRemoveUnable']);
    }
}

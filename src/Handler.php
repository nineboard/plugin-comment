<?php
/**
 * Handler.php
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

use Illuminate\Pagination\Paginator;
use Illuminate\Session\Store as SessionStore;
use Xpressengine\Config\ConfigEntity;
use Xpressengine\Config\ConfigManager;
use Xpressengine\Counter\Counter;
use Xpressengine\Counter\Models\CounterLog;
use Xpressengine\Document\DocumentHandler;
use Xpressengine\Http\Request;
use Xpressengine\Keygen\Keygen;
use Xpressengine\Permission\Grant;
use Xpressengine\Permission\PermissionHandler;
use Xpressengine\Plugins\Comment\Exceptions\InstanceIdGenerateFailException;
use Xpressengine\Plugins\Comment\Exceptions\WrongConfigurationException;
use Xpressengine\Plugins\Comment\Models\Comment;
use Xpressengine\Plugins\Comment\Plugin as CommentPlugin;
use Xpressengine\User\GuardInterface as Authenticator;
use Xpressengine\User\Models\Guest;
use Xpressengine\User\UserInterface;

/**
 * Handler
 *
 * @category    Comment
 *
 * @author      XE Developers <developers@xpressengine.com>
 * @copyright   2019 Copyright XEHub Corp. <https://www.xehub.io>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 *
 * @link        https://xpressengine.io
 */
class Handler
{
    const PLUGIN_PREFIX = 'comment';

    const COUNTER_VOTE = 'comment_vote';

    protected $documents;

    protected $session;

    protected $counter;

    protected $auth;

    protected $permissions;

    protected $configs;

    protected $keygen;

    protected $instanceMap;

    protected $model = Comment::class;

    const REMOVE_BATCH = 'batch';

    /**
     * @deprecated since rc.8. instead use Handler::REMOVE_BLIND
     */
    const REMOVE_BlIND = 'blind';

    const REMOVE_BLIND = 'blind';

    const REMOVE_UNABLE = 'unable';

    private $defaultConfig = [
        'division' => false,
        'useAssent' => false,
        'useDissent' => false,
        'useApprove' => false,
        'secret' => false,
        'perPage' => 20,
        'removeType' => self::REMOVE_BATCH,
        'reverse' => false,
    ];

    /**
     * Handler constructor.
     *
     * @param  DocumentHandler  $documents  document handler
     * @param  SessionStore  $session  session store
     * @param  Counter  $counter  counter
     * @param  Authenticator  $auth  auth
     * @param  PermissionHandler  $permissions  permission handler
     * @param  ConfigManager  $configs  config manager
     * @param  Keygen  $keygen  keygen
     */
    public function __construct(
        DocumentHandler $documents,
        SessionStore $session,
        Counter $counter,
        Authenticator $auth,
        PermissionHandler $permissions,
        ConfigManager $configs,
        Keygen $keygen
    ) {
        $this->documents = $documents;
        $this->session = $session;
        $this->counter = $counter;
        $this->auth = $auth;
        $this->permissions = $permissions;
        $this->configs = $configs;
        $this->keygen = $keygen;
    }

    /**
     * 새로운 인스턴스 설정
     *
     * @param  string  $targetInstanceId  target instance identifier
     * @param  bool  $division  if true, set table division
     * @return void
     */
    public function createInstance($targetInstanceId, $division = false)
    {
        if ($this->getInstanceId($targetInstanceId)) {
            throw new \RuntimeException(sprintf('Already exists comment instance for "%s"', $targetInstanceId));
        }

        $instanceId = $this->createInstanceId();
        $this->documents->createInstance($instanceId, ['division' => $division]);

        $this->configs->set($this->getKeyForConfig($instanceId), [
            'division' => $division,
        ]);

        $this->instanceMapping($targetInstanceId, $instanceId);

        $this->permissions->register($this->getKeyForPerm($instanceId), new Grant());
    }

    /**
     * 대상 인스턴스와 댓글 인스턴스 맵핑
     *
     * @param  string  $targetInstanceId  target instance identifier
     * @param  string  $commentInstanceId  comment instance identifier
     * @return void
     */
    protected function instanceMapping($targetInstanceId, $commentInstanceId)
    {
        $config = $this->configs->get('comment_map');
        $config->set($targetInstanceId, $commentInstanceId);

        $this->configs->modify($config);

        $this->instanceMap = array_merge($this->instanceMap ?: [], [$targetInstanceId => $commentInstanceId]);
    }

    /**
     * Get instance map
     *
     * @return array
     */
    public function getInstanceMap()
    {
        if (! $this->instanceMap) {
            $this->instanceMap = [];
            $config = $this->configs->get('comment_map');
            foreach ($config as $target => $id) {
                $this->instanceMap[$target] = $id;
            }
        }

        return $this->instanceMap;
    }

    /**
     * Get instance id by target instance id
     *
     * @param  string  $targetInstanceId  target instance identifier
     * @return string|null
     */
    public function getInstanceId($targetInstanceId)
    {
        $map = $this->getInstanceMap();

        return isset($map[$targetInstanceId]) ? $map[$targetInstanceId] : null;
    }

    /**
     * Get target instance id by comment instance id
     *
     * @param  string  $instanceId  comment instance identifier
     * @return string|null
     */
    public function getTargetInstanceId($instanceId)
    {
        $map = $this->getInstanceMap();
        if ($key = array_search($instanceId, $map)) {
            return $key;
        }

        return null;
    }

    /**
     * Get key for config
     *
     * @param  string|null  $instanceId  comment instance identifier
     * @return string
     */
    protected function getKeyForConfig($instanceId = null)
    {
        return static::PLUGIN_PREFIX.($instanceId ? '.'.$instanceId : '');
    }

    /**
     * 설정 등록
     *
     * @param  string  $instanceId  comment instance identifier
     * @param  array  $information  config data
     * @return void
     */
    public function configure($instanceId, array $information)
    {
        $key = $this->getKeyForConfig($instanceId);

        if (! $config = $this->configs->get($key)) {
            throw new \RuntimeException('Instance was not created');
        }

        if ($instanceId === null) {
            // global 설정에는 모든 설정이 등록될 수 있도록 함
            $information = array_merge($this->defaultConfig, $information);
        }

        $information = array_only($information, array_keys($this->defaultConfig));
        // division 설정은 최초 인스턴스 생성시 결정되며 변경할 수 없다.
        $information = array_merge($information, ['division' => $config->get('division')]);

        $this->configs->put($key, $information);
    }

    /**
     * 인스턴스 유무
     *
     * @param  string  $instanceId  instance identifier
     * @return bool
     */
    public function existInstance($instanceId)
    {
        $commentMap = $this->configs->get('comment_map')->all();

        if (array_key_exists($instanceId, $commentMap)) {
            return true;
        }

        return $this->configs->get($this->getKeyForConfig($instanceId)) !== null;
    }

    /**
     * instance 에 속한 comment 를 제거함, table 도 삭제 됨
     *
     * @param  string  $instanceId  instance identifier
     * @return void
     *
     * @throws \Exception
     */
    public function drop($instanceId)
    {
        $key = $this->getKeyForConfig($instanceId);
        if (! $config = $this->configs->get($key)) {
            throw new \Exception();
        }

        // 실질적인 comment row 의 삭제는 document 쪽에서 instance 삭제시 처리함
        $this->documents->destroyInstance($instanceId);

        $this->configs->remove($config);

        $target = $this->getTargetInstanceId($instanceId);
        $this->configs->setVal(implode('.', ['comment_map', $target]), null);
    }

    /**
     * Get config
     *
     * @param  string|null  $instanceId  comment instance identifier
     * @return \Xpressengine\Config\ConfigEntity
     */
    public function getConfig($instanceId = null)
    {
        $config = $this->configs->get($this->getKeyForConfig($instanceId));

        if ($config === null) {
            $config = $this->configs->getOrNew($this->getKeyForConfig($instanceId));
            foreach ($this->defaultConfig as $key => $value) {
                $config->set($key, $value);
            }
        }

        return $config;
    }

    /**
     * 등록
     *
     * @param  array  $inputs  inputs
     * @param  UserInterface|null  $user  user object
     * @return Comment
     */
    public function create(array $inputs, ?UserInterface $user = null)
    {
        $inputs['type'] = CommentPlugin::getId();
        $inputs['title'] = $inputs['title'] ?? '';
        $inputs['head'] = $inputs['head'] ?? '';
        $inputs['certify_key'] = $inputs['certify_key'] ?? '';

        $user = $user ?: $this->auth->user();
        if (! $user instanceof Guest) {
            $inputs['user_id'] = $user->getId();
            $inputs['writer'] = $user->getDisplayName();
        } else {
            $inputs['user_id'] = '';
        }

        $doc = $this->documents->add($inputs);
        /** @var Comment $comment */
        $comment = $this->createModel($inputs['instance_id'])->newQuery()->find($doc->getKey());
        $comment->target()->create([
            'target_id' => $inputs['target_id'],
            'target_author_id' => $inputs['target_author_id'],
            'target_type' => $inputs['target_type'],
        ]);

        if ($user instanceof Guest) {
            $this->certified($comment);
        }

        $config = $this->getConfig($comment->instance_id);
        if ($config->get('useApprove') === true) {
            $comment = $this->put($comment->setApproveWait());
        }

        return $comment;
    }

    /**
     * 수정
     *
     * @param  Comment  $comment  comment object
     * @return Comment
     */
    public function put(Comment $comment)
    {
        if ($comment->isDirty()) {
            return $this->documents->put($comment);
        }

        return $comment;
    }

    /**
     * 휴지통으로 이동
     *
     * @param  Comment  $comment  comment object
     * @return Comment
     */
    public function trash(Comment $comment)
    {
        if ($this->hasChild($comment)) {
            $config = $this->getConfig($comment->instance_id);
            if ($config->get('removeType') === static::REMOVE_UNABLE) {
                return false;
            }

            $comment->setTrash();

            if ($config->get('removeType') === static::REMOVE_BATCH) {
                $this->createModel()->newQuery()
                    ->where('head', $comment->head)
                    ->where('reply', 'like', $comment->reply.str_repeat('_', Comment::getReplyCharLen()))
                    ->get()->each(function ($child) {
                        $this->trash($child);
                    });
            } elseif ($config->get('removeType') === static::REMOVE_BLIND) {
                $comment->setDisplay(Comment::DISPLAY_VISIBLE);
            } else {
                throw new WrongConfigurationException;
            }
        } else {
            $comment->setTrash();
        }

        return $this->put($comment);
    }

    /**
     * 휴지통에서 복구
     *
     * @param  Comment  $comment  comment object
     * @return Comment|false
     */
    public function restore(Comment $comment)
    {
        if (! empty($comment->reply)) {
            $parent = $this->createModel($comment->instance_id)->newQuery()
                ->where('head', $comment->head)
                ->where('reply', substr($comment->reply, 0, -1 * Comment::getReplyCharLen()))
                ->first();

            if (! $parent || $parent->display != Comment::DISPLAY_VISIBLE) {
                return false;
            }
        }

        $comment->setRestore();

        return $this->put($comment);
    }

    /**
     * 삭제
     *
     * @param  Comment  $comment  comment object
     * @return bool
     *
     * @throws \Exception
     */
    public function remove(Comment $comment)
    {
        if ($comment->status === Comment::STATUS_TRASH && $comment->display === Comment::DISPLAY_HIDDEN) {
            $this->createModel($comment->instance_id)->newQuery()
                ->where('head', $comment->head)
                ->where('reply', 'like', $comment->reply.str_repeat('_', Comment::getReplyCharLen()))
                ->get()->each(function ($child) {
                    $this->remove($child);
                });

            $comment->target->delete();

            return $this->documents->remove($comment);
        }

        return false;
    }

    /**
     * 승인
     *
     * @param  Comment  $comment  comment object
     * @return Comment
     */
    public function approve(Comment $comment)
    {
        return $this->put($comment->setApprove());
    }

    /**
     * 승인 반려
     *
     * @param  Comment  $comment  comment object
     * @return Comment
     */
    public function reject(Comment $comment)
    {
        $comment->setReject();

        if ($this->hasChild($comment)) {
            $comment->setDisplay(Comment::DISPLAY_VISIBLE);
        }

        return $this->put($comment);
    }

    /**
     * 자식에 해당하는 댓글이 있는지 확인
     *
     * @param  Comment  $comment  comment object
     * @return bool
     */
    protected function hasChild(Comment $comment)
    {
        return $this->createModel($comment->instance_id)->newQuery()
            ->where('head', $comment->head)
            ->where('reply', 'like', $comment->reply.str_repeat('_', Comment::getReplyCharLen()))
            ->count() > 0;
    }

    /**
     * session 에서 사용될 key 를 반환
     *
     * @return string
     */
    protected function getKeyForCertified()
    {
        return static::PLUGIN_PREFIX.'.certified';
    }

    /**
     * 현재 사용자에 해당 댓글이 인증되었다고 표시함
     *
     * @param  Comment  $comment  comment instance
     * @return void
     */
    public function certified(Comment $comment)
    {
        $key = $this->getKeyForCertified();

        if (! $data = $this->session->get($key)) {
            $data = [];
        }

        $this->session->put($key, array_merge($data, [$comment->id => time() + 600]));
    }

    /**
     * 현재 사용자가 해당 댓글에 인증이 되었는지 판별
     *
     * @param  Comment  $comment  comment instance
     * @return bool
     */
    public function isCertified(Comment $comment)
    {
        $data = $this->session->get($this->getKeyForCertified());

        return ! (! $data || ! isset($data[$comment->id]) || $data[$comment->id] < time());
    }

    /**
     * 찬성 or 추천 or 좋아요
     *
     * @param  Comment  $comment  comment entity
     * @param  string  $option  'assent' or 'dissent'
     * @param  UserInterface|null  $author  user instance
     * @return bool
     */
    public function addVote(Comment $comment, $option, ?UserInterface $author = null)
    {
        $author = $author ?: $this->auth->user();

        if ($this->isVoteable($comment, $author, $option)) {
            $this->counter->add($comment->id, $author, $option);

            $column = $this->voteOptToColumn($option);
            $comment->{$column} = $comment->{$column} + 1;

            $this->documents->put($comment);

            return true;
        }

        return false;
    }

    /**
     * 투표 가능 여부 확인
     *
     * @param  Comment  $comment  comment object
     * @param  UserInterface  $author  user
     * @param  string  $option  option (assent or dissent)
     * @return bool
     */
    private function isVoteable(Comment $comment, UserInterface $author, $option)
    {
        return ! $this->counter->has($comment->id, $author, $option);
    }

    /**
     * 반대 or 비추천 or 싫어요
     *
     * @param  Comment  $comment  comment entity
     * @param  string  $option  'assent' or 'dissent'
     * @param  UserInterface|null  $author  user instance
     * @return bool
     */
    public function removeVote(Comment $comment, $option, ?UserInterface $author = null)
    {
        $author = $author ?: $this->auth->user();

        if ($this->counter->has($comment->id, $author, $option)) {
            $this->counter->remove($comment->id, $author, $option);

            $column = $this->voteOptToColumn($option);
            $comment->{$column} = $comment->{$column} - 1;

            $this->documents->put($comment);

            return true;
        }

        return false;
    }

    /**
     * 값에 따른 컬럼명 반환
     *
     * @param  string  $opt  'assent' or 'dissent'
     * @return string
     */
    private function voteOptToColumn($opt)
    {
        if ($opt === 'assent') {
            $column = 'assent_count';
        } elseif ($opt === 'dissent') {
            $column = 'dissent_count';
        } else {
            throw new \InvalidArgumentException;
        }

        return $column;
    }

    /**
     * 투표자 목록 반환
     *
     * @param  Comment  $comment  comment object
     * @param  string  $option  'assent' or 'dissent'
     * @return array
     */
    public function voteUsers(Comment $comment, $option)
    {
        return $this->counter->getUsers($comment->id, $option);
    }

    /**
     * 투표자 수 반환
     *
     * @param  Comment  $comment  comment object
     * @param  string  $option  'assent' or 'dissent'
     * @return int
     */
    public function voteUserCount(Comment $comment, $option)
    {
        return $this->counter->getPoint($comment->id, $option);
    }

    /**
     * 현재 사용자의 투표정보 주입
     *
     * @param  Comment  $comment  comment object
     * @return void
     */
    public function bindUserVote(Comment $comment)
    {
        if (! $this->auth->guest() && $log = $this->counter->getByName($comment->id, $this->auth->user())) {
            $comment->setVoteType($log->counter_option);
        }
    }

    /**
     * 투표 목록
     *
     * @param  Comment  $comment  comment object
     * @param  string  $option  'assent' or 'dissent'
     * @param  string|null  $startId  start id
     * @param  int  $limit  limit count
     * @return mixed
     */
    public function votedList(Comment $comment, $option, $startId = null, $limit = 10)
    {
        $query = $this->counter->newModel()->where('counter_name', static::COUNTER_VOTE)
            ->where('target_id', $comment->id)->where('counter_option', $option);

        if ($startId) {
            $query->where('id', '<', $startId);
        }

        return $query->orderBy('id', 'desc')->take($limit)->get();
    }

    /**
     * 대상 객체에 속하는 댓글을 이동시킴
     *
     * @param  CommentUsable  $target  comment usable instance
     * @return void
     *
     * @throws \Exception
     */
    public function moveByTarget(CommentUsable $target)
    {
        if (! $newInstanceId = $this->getInstanceId($target->getInstanceId())) {
            throw new \Exception;
        }

        $model = $this->createModel();
        $comments = $model->newQuery()->whereHas('target', function ($query) use ($target) {
            $query->where('target_id', $target->getUid());
        })->get();

        foreach ($comments as $comment) {
            $comment->instance_id = $newInstanceId;

            $this->documents->put($comment);
        }
    }

    /**
     * Get default config information
     *
     * @return array
     */
    public function getDefaultConfig()
    {
        return $this->defaultConfig;
    }

    /**
     * Get key for permission
     *
     * @param  string|null  $instanceId  comment instance id
     * @return string
     */
    public function getKeyForPerm($instanceId = null)
    {
        $name = static::PLUGIN_PREFIX;

        return $instanceId === null ? $name : $name.'.'.$instanceId;
    }

    /**
     * Create new instance id
     *
     * @return string
     *
     * @throws InstanceIdGenerateFailException
     */
    protected function createInstanceId()
    {
        $map = $this->getInstanceMap();
        $try = 0;

        do {
            if ($try > 20) {
                throw new InstanceIdGenerateFailException;
            }
            $instanceId = substr(str_replace('-', '', $this->keygen->generate()), 0, 12);

            $try++;
        } while (array_search($instanceId, $map) !== false);

        return $instanceId;
    }

    /**
     * Create model
     *
     * @param  string  $instanceId  comment instance id
     * @return Comment
     */
    public function createModel($instanceId = null)
    {
        $class = $this->getModel();

        /** @var Comment $instance */
        $instance = new $class;

        if ($instanceId !== null) {
            $instance->setDivision($instanceId);
        }

        return $instance;
    }

    /**
     * Get model
     *
     * @return string
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * Set model
     *
     * @param  string  $model  comment model class
     * @return void
     */
    public function setModel($model)
    {
        $this->model = '\\'.ltrim($model, '\\');
    }

    /**
     * Get Comment Items
     *
     * @return Paginator
     */
    public function getItems(Request $request, ConfigEntity $config, $query)
    {
        $counterName = $this->counter->getName();

        $useDissent = $config->get('useDissent', false);
        $useApprove = $config->get('useApprove', false);
        $useVote = $useDissent === true || $useApprove === true;

        $offsetHead = $request->get('offsetHead');
        $offsetHead = empty($offsetHead) === false ? $offsetHead : null;

        $offsetReply = $request->get('offsetReply');
        $offsetReply = empty($offsetReply) === false ? $offsetReply : null;

        $take = $request->get('perPage', $config['perPage']);
        $direction = $config->get('reverse') === true ? 'asc' : 'desc';

        $query->when(
            $offsetHead !== null,
            function ($query) use ($offsetHead, $offsetReply, $direction) {
                $operator = $direction == 'desc' ? '<' : '>';
                $offsetReply = $offsetReply ?: '';

                $query->where(
                    function ($query) use ($offsetHead, $operator, $offsetReply) {
                        $query->where('head', $offsetHead);
                        $query->where('reply', $operator, $offsetReply);
                        $query->orWhere('head', '<', $offsetHead);
                    }
                );
            }
        );

        $query->with([
            'author',
            'files',
            'target.commentable',
        ]);

        $query->orderBy('head', 'desc')->orderBy('reply', $direction);

        $comments = $query->take($take + 1)->get();

        if ($this->auth->guest() === false && $useVote === true) {
            $commentVoteCounterLogQuery = $this->counter->newModel()->newQuery();

            $commentVoteCounterLogs = $commentVoteCounterLogQuery
                ->where('counter_name', $counterName)
                ->where('user_id', $this->auth->id())
                ->whereIn('target_id', $comments->pluck('id'))
                ->get();

            $comments->each(function (Comment $comment) use ($commentVoteCounterLogs) {
                $commentVoteCounterLog = $commentVoteCounterLogs->first(
                    function (CounterLog $counterLog) use ($comment) {
                        return $counterLog->target_id === $comment->id;
                    }
                );

                if ($commentVoteCounterLog !== null) {
                    $comment->setVoteType($commentVoteCounterLog->counter_option);
                }
            });
        }

        return new Paginator($comments, $take);
    }
}

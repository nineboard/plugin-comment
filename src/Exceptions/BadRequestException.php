<?php
/**
 * BadRequestException.php
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

namespace Xpressengine\Plugins\Comment\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Xpressengine\Plugins\Comment\CommentException;

/**
 * BadRequestException
 *
 * @category    Comment
 *
 * @author      XE Developers <developers@xpressengine.com>
 * @copyright   2019 Copyright XEHub Corp. <https://www.xehub.io>
 * @license     http://www.gnu.org/licenses/lgpl-3.0-standalone.html LGPL
 *
 * @link        https://xpressengine.io
 */
class BadRequestException extends CommentException
{
    protected $message = 'comment::BadRequest';

    protected $statusCode = Response::HTTP_BAD_REQUEST;
}

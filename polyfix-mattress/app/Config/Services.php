<?php

namespace Config;

use App\Libraries\RequestContext;
use CodeIgniter\Config\BaseService;

/**
 * Application services. `service('name')` returns the shared instance for the
 * current request.
 */
class Services extends BaseService
{
    /** Who is acting in this request. Filled by AuthFilter from the server-side session. */
    public static function requestContext(bool $getShared = true): RequestContext
    {
        if ($getShared) {
            return static::getSharedInstance('requestContext');
        }

        return new RequestContext();
    }
}

<?php

/**
 * Toggles a single feed's exclusion from error back-off, reached from the
 * "switch" button on the extension's configure page (see configure.phtml) -
 * same interaction pattern FreshRSS itself uses to enable/disable an
 * extension, so a single click takes effect immediately without touching the
 * rest of the configure form. See issue #50.
 */
class FreshExtension_backoff_Controller extends Minz_ActionController
{
    public function firstAction(): void
    {
        if (!FreshRSS_Auth::hasAccess()) {
            Minz_Error::error(403);
        }
    }

    public function toggleAction(): void
    {
        // configure.phtml pre-urlencode()s the name before handing it to _url(),
        // same as FreshRSS core's own extension configure/enable/disable links -
        // undo that one layer here (PHP's request parsing already undid the
        // other, from the URL itself).
        $extName = urldecode(Minz_Request::paramString('e'));
        $urlRedirect = ['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $extName]];

        if (Minz_Request::isPost()) {
            $feedId = Minz_Request::paramInt('id');

            $excluded = array_map('intval', FreshRSS_Context::userConf()->attributeArray('auto_ttl_backoff_excluded_feeds') ?? []);
            if (in_array($feedId, $excluded, true)) {
                $excluded = array_values(array_diff($excluded, [$feedId]));
            } else {
                $excluded[] = $feedId;
            }

            FreshRSS_Context::userConf()->_attribute('auto_ttl_backoff_excluded_feeds', $excluded);
            FreshRSS_Context::userConf()->save();
        }

        Minz_Request::forward($urlRedirect, true);
    }
}

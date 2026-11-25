<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What a request does to the data, in the three words an administrator limits a person by: adding, editing, deleting.
 * A request that only reads is none of them (null).
 *
 * The HTTP method says most of it; a POST is told apart by the name of the controller method it reaches, which says what
 * the route is for (`store`, `import…` add; `destroy`, `purge…` delete; `preview`, `export…` only read; the rest change
 * something that exists).
 */
class WriteAction
{
    public const ADD = 'add';

    public const EDIT = 'edit';

    public const DELETE = 'delete';

    public const ALL = [self::ADD, self::EDIT, self::DELETE];

    private const DELETES = '/^(destroy|delete|remove|purge|forceDelete|bulkDelete|bulkDestroy|clear|void)/';

    private const READS = '/^(preview|validate|check|inspect|parse|export|search|lookup|heartbeat|presence|seen|read|markRead|markAllRead|estimate|download|simulate|dryRun)/';

    private const ADDS = '/^(store|create|add|import|duplicate|clone|copy|upload|generate|register|invite|bulkStore|bulkCreate)/';

    public static function of(Request $request): ?string
    {
        $verb = strtoupper($request->method());
        if (in_array($verb, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }
        if ($verb === 'DELETE') {
            return self::DELETE;
        }
        $method = (string) $request->route()?->getActionMethod();

        return self::named($verb, $method);
    }

    /** The same decision from the verb and the controller method alone (tested without a request). */
    public static function named(string $verb, string $method): ?string
    {
        $verb = strtoupper($verb);
        if (in_array($verb, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }
        if ($verb === 'DELETE' || preg_match(self::DELETES, $method)) {
            return self::DELETE;
        }
        if ($verb !== 'POST') {
            return self::EDIT;
        }
        if (preg_match(self::READS, $method)) {
            return null;
        }

        return preg_match(self::ADDS, $method) ? self::ADD : self::EDIT;
    }

    /** The area a right belongs to: `saber.day.edit` → `saber`. */
    public static function area(string $slug): string
    {
        return explode('.', $slug, 2)[0];
    }
}

<?php
/**
 * CFP.DEV shortcodes
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests;

/** Thrown in place of the process exit performed by `wp_send_json_*()`. */
final class JsonResponseSent extends \RuntimeException {
}

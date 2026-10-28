<?php

namespace Merserwis\Plugin\System\MerTools\Feature;

\defined('_JEXEC') or die;

use Merserwis\Plugin\System\MerTools\Tool\Hygiene as HygieneTool;

/**
 * Site hygiene (tab Site hygiene): the page as it is sent, tidied for what audits of a site keep finding.
 * The pure parts are in Tool\Hygiene.
 */
final class Hygiene extends Feature
{
    /** The page: campaign tags out of og:url (on by default), the language code of <html> (off by default). */
    public function html(string $html): string
    {
        $params = $this->plugin->params;
        try {
            if ((int) $params->get('hygiene_og_url', 1)) {
                $html = HygieneTool::cleanUrls($html);
            }
            if ((int) $params->get('hygiene_lang', 0)) {
                $html = HygieneTool::shortLang($html);
            }
        } catch (\Throwable $e) {
            // a page is never broken by tidying: sent as it was made
        }

        return $html;
    }

    /**
     * The headers of the response: Secure and SameSite on the cookies, the policy against loading from other
     * places, and no X-XSS-Protection (obsolete, and in old browsers a weakness of its own).
     *
     * @param bool $page  an HTML page (not a file or an answer to a script): only these get the policy
     */
    public function headers(bool $page): void
    {
        if (headers_sent()) {
            return;
        }
        $params = $this->plugin->params;
        $app    = $this->plugin->app();
        try {
            if ((int) $params->get('hygiene_cookies', 1)) {
                $lines = HygieneTool::cookies(headers_list(), $app->isSSLConnection());
                if ($lines !== null) {
                    header_remove('Set-Cookie');
                    foreach ($lines as $line) {
                        header('Set-Cookie: ' . $line, false);
                    }
                }
            }
            if ((int) $params->get('hygiene_xss', 1)) {
                header_remove('X-XSS-Protection');
                $kept = array_filter($app->getHeaders(), fn ($h) => strtolower((string) ($h['name'] ?? '')) !== 'x-xss-protection');
                if (\count($kept) !== \count($app->getHeaders())) {
                    $app->clearHeaders();
                    foreach ($kept as $h) {
                        $app->setHeader($h['name'], $h['value'], false);
                    }
                }
            }
            if ($page) {
                $csp = HygieneTool::csp((string) $params->get('hygiene_csp_mode', 'off'), (string) $params->get('hygiene_csp', HygieneTool::CSP_DEFAULT));
                if ($csp !== null) {
                    // added to a policy the site already has (Joomla's list of headers, or one sent by PHP's
                    // header()), never put in its place: a stricter policy of the site stays in force
                    $existing = [];
                    foreach ($app->getHeaders() as $h) {
                        if (strcasecmp((string) ($h['name'] ?? ''), $csp[0]) === 0) {
                            $existing[] = (string) $h['value'];
                        }
                    }
                    foreach (headers_list() as $line) {
                        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
                        if (strcasecmp(trim($name), $csp[0]) === 0) {
                            $existing[] = trim($value);
                        }
                    }
                    $app->setHeader($csp[0], HygieneTool::withPolicy($existing, $csp[1]), true);
                }
            }
        } catch (\Throwable $e) {
            // the headers stay as they are
        }
    }
}

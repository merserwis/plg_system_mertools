<?php

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

use Joomla\CMS\Mail\Mail;

/**
 * Joomla's mailer that sends nothing: put in Factory::$mailer for one request of Gridbox (the status of
 * an order changed without its e-mails), every Factory::getMailer() of that request is a copy of it.
 */
final class QuietMail extends Mail
{
    /** @var int the e-mails held back in this request */
    public static int $held = 0;

    public function Send()
    {
        self::$held++;

        return true;
    }
}

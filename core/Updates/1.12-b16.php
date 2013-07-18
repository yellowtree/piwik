<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Updates
 */
use Piwik\Core\Piwik_Common;

/**
 * @package Updates
 */
class Piwik_Updates_1_12_b16 extends Piwik_Updates
{
    static function getSql($schema = 'Myisam')
    {
        return array(
            // ignore existing column name error (1060)
            'ALTER TABLE ' . Piwik_Common::prefixTable('report')
                . " ADD COLUMN idsegment INT(11) AFTER description" => 1060,
        );
    }

    static function update()
    {
        Piwik_Updater::updateDatabase(__FILE__, self::getSql());
    }
}
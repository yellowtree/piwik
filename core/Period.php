<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */
namespace Piwik;

use Exception;
use Piwik\Period\Day;
use Piwik\Period\Month;
use Piwik\Period\Range;
use Piwik\Period\Week;
use Piwik\Period\Year;

/**
 * Date range representation.
 * 
 * Piwik allows users to view aggregated statistics for each day and for date
 * ranges consisting of several days. When requesting data, a _date_ string and
 * a _period_ string must be used to specify the date range to view statistics
 * for. This is the class that Piwik uses to represent and manipulate those
 * date ranges.
 * 
 * There are five types of periods in Piwik: day, week, month, year and range,
 * where **range** is any date range. The reason the other periods exist instead
 * of just **range** is that Piwik will archive for days, weeks, months and years
 * periodically, while every other date range is archived on-demand.
 * 
 * ### Examples
 * 
 * **Building a period from 'date' and 'period' query parameters**
 * 
 *     $date = Common::getRequestVar('date', null, 'string');
 *     $period = Common::getRequestVar('period', null, 'string');
 *     $periodObject = Period::advancedFactory($period, $date);
 * 
 * @package Piwik
 * @subpackage Period
 * @api
 */
abstract class Period
{
    /**
     * Array of subperiods
     * @var \Piwik\Period[]
     */
    protected $subperiods = array();
    protected $subperiodsProcessed = false;

    /**
     * @var string
     */
    protected $label = null;

    /**
     * @var Date
     */
    protected $date = null;

    /**
     * Constructor.
     * 
     * @param Date $date
     * @ignore
     */
    public function __construct(Date $date)
    {
        $this->date = clone $date;
    }

    /**
     * Creates a new Period instance with a period ID and Date instance.
     * 
     * Note: This method cannot create Range periods.
     * 
     * @param string $strPeriod `"day"`, `"week"`, `"month"`, `"year"`, `"range"`.
     * @param Date|string $date A date within the period or the range of dates.
     * @throws Exception If `$strPeriod` is invalid.
     * @return \Piwik\Period
     */
    static public function factory($strPeriod, $date)
    {
        if (is_string($date)) {
            if (Period::isMultiplePeriod($date, $strPeriod) || $strPeriod == 'range') {
                return new Range($strPeriod, $date);
            }

            $date = Date::factory($date);
        }

        switch ($strPeriod) {
            case 'day':
                return new Day($date);
                break;

            case 'week':
                return new Week($date);
                break;

            case 'month':
                return new Month($date);
                break;

            case 'year':
                return new Year($date);
                break;

            default:
                $message = Piwik::translate(
                    'General_ExceptionInvalidPeriod', array($strPeriod, 'day, week, month, year, range'));
                throw new Exception($message);
                break;
        }
    }

    /**
     * Returns true $dateString and $period correspond to multiple periods.
     *
     * @static
     * @param  $dateString The `'date'` query parameter value.
     * @param  $period The `'period'` query parameter value.
     * @return boolean
     */
    public static function isMultiplePeriod($dateString, $period)
    {
        return is_string($dateString)
            && (preg_match('/^(last|previous){1}([0-9]*)$/D', $dateString, $regs)
                || Range::parseDateRange($dateString))
            && $period != 'range';
    }

    /**
     * Creates a period instance using a Site instance and two strings describing
     * the period & date.
     *
     * @param string $timezone
     * @param string $period The period string: day, week, month, year, range
     * @param string $date The date or date range string. Can be a special value including
     *                     `'now'`, `'today'`, `'yesterday'`, `'yesterdaySameTime'`.
     * @return \Piwik\Period
     */
    public static function makePeriodFromQueryParams($timezone, $period, $date)
    {
        if (empty($timezone)) {
            $timezone = 'UTC';
        }

        if ($period == 'range') {
            $oPeriod = new Period\Range('range', $date, $timezone, Date::factory('today', $timezone));
        } else {
            if (!($date instanceof Date)) {
                if ($date == 'now' || $date == 'today') {
                    $date = date('Y-m-d', Date::factory('now', $timezone)->getTimestamp());
                } elseif ($date == 'yesterday' || $date == 'yesterdaySameTime') {
                    $date = date('Y-m-d', Date::factory('now', $timezone)->subDay(1)->getTimestamp());
                }
                $date = Date::factory($date);
            }
            $oPeriod = Period::factory($period, $date);
        }
        return $oPeriod;
    }

    /**
     * Returns the first day of the period.
     *
     * @return Date
     */
    public function getDateStart()
    {
        $this->generate();
        if (count($this->subperiods) == 0) {
            return $this->getDate();
        }
        $periods = $this->getSubperiods();
        /** @var $currentPeriod Period */
        $currentPeriod = $periods[0];
        while ($currentPeriod->getNumberOfSubperiods() > 0) {
            $periods = $currentPeriod->getSubperiods();
            $currentPeriod = $periods[0];
        }
        return $currentPeriod->getDate();
    }

    /**
     * Returns the last day of the period.
     *
     * @return Date
     */
    public function getDateEnd()
    {
        $this->generate();
        if (count($this->subperiods) == 0) {
            return $this->getDate();
        }
        $periods = $this->getSubperiods();
        /** @var $currentPeriod Period */
        $currentPeriod = $periods[count($periods) - 1];
        while ($currentPeriod->getNumberOfSubperiods() > 0) {
            $periods = $currentPeriod->getSubperiods();
            $currentPeriod = $periods[count($periods) - 1];
        }
        return $currentPeriod->getDate();
    }

    /**
     * Returns the period ID.
     * 
     * @return int A integer unique to this type of period.
     */
    public function getId()
    {
        return Piwik::$idPeriods[$this->getLabel()];
    }

    /**
     * Returns the label for the current period.
     * 
     * @return string `"day"`, `"week"`, `"month"`, `"year"`, `"range"`
     */
    public function getLabel()
    {
        return $this->label;
    }

    /**
     * @return Date
     */
    protected function getDate()
    {
        return $this->date;
    }

    protected function generate()
    {
        $this->subperiodsProcessed = true;
    }

    /**
     * Returns the number of available subperiods.
     * 
     * @return int
     */
    public function getNumberOfSubperiods()
    {
        $this->generate();
        return count($this->subperiods);
    }

    /**
     * Returns the set of Period instances that together make up this period. For a year,
     * this would be 12 months. For a month this would be 28-31 days. Etc.
     * 
     * @return Period[]
     */
    public function getSubperiods()
    {
        $this->generate();
        return $this->subperiods;
    }

    /**
     * Add a date to the period.
     *
     * Protected because it not yet supported to add periods after the initialization
     *
     * @param \Piwik\Period $period Valid Period object
     */
    protected function addSubperiod($period)
    {
        $this->subperiods[] = $period;
    }

    /**
     * Returns a list of strings representing the current period.
     *
     * @param string $format The format of each individual day.
     * @return array An array of string dates that this period consists of.
     */
    public function toString($format = "Y-m-d")
    {
        $this->generate();
        $dateString = array();
        foreach ($this->subperiods as $period) {
            $dateString[] = $period->toString($format);
        }
        return $dateString;
    }

    /**
     * See {@link toString()}.
     * 
     * @return string
     */
    public function __toString()
    {
        return implode(",", $this->toString());
    }

    /**
     * Returns a pretty string describing this period.
     * 
     * @return string
     */
    abstract public function getPrettyString();

    /**
     * Returns a short string description of this period that is localized with the currently used
     * language.
     * 
     * @return string
     */
    abstract public function getLocalizedShortString();

    /**
     * Returns a long string description of this period that is localized with the currently used
     * language.
     * 
     * @return string
     */
    abstract public function getLocalizedLongString();

    /**
     * Returns a succinct string describing this period.
     * 
     * @return string eg, `'2012-01-01,2012-01-31'`.
     */
    public function getRangeString()
    {
        return $this->getDateStart()->toString("Y-m-d") . "," . $this->getDateEnd()->toString("Y-m-d");
    }
}

<?php

namespace GeorgRinger\Eventnews\Hooks;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use GeorgRinger\Eventnews\Domain\Model\Dto\Demand;
use GeorgRinger\News\Utility\ConstraintHelper;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;

class AbstractDemandedRepository
{

    /**
     * Modify the constraints used in the query
     *
     * @param array $params
     * @return void
     */
    public function modify(array $params)
    {
        if (get_class($params['demand']) !== 'GeorgRinger\\Eventnews\\Domain\\Model\\Dto\\Demand') {
            return;
        }

        $this->updateEventConstraints($params['demand'], $params['respectEnableFields'], $params['query'],
            $params['constraints']);
    }


    /**
     * Update the main event constraints
     *
     * @param Demand $demand
     * @param bool $respectEnableFields
     * @param \TYPO3\CMS\Extbase\Persistence\QueryInterface $query
     * @param array $constraints
     * @return void
     */
    protected function updateEventConstraints(
        Demand $demand,
        $respectEnableFields,
        \TYPO3\CMS\Extbase\Persistence\QueryInterface $query,
        array &$constraints
    )
    {
        $eventRestriction = $demand->getEventRestriction();

        /** @var QueryInterface $query */
        if ($eventRestriction === Demand::EVENT_RESTRICTION_NO_EVENTS) {
            $constraints[] = $query->equals('isEvent', 0);
        } elseif ($eventRestriction === Demand::EVENT_RESTRICTION_ONLY_EVENTS) {
            // reset datetime constraint
            unset($constraints['datetime']);
            $constraints[] = $query->equals('isEvent', 1);

            if ($demand->getMonth() && $demand->getYear()) {
                $dateField = $demand->getDateField();
                if ($demand->getDay()) {
                    $begin = mktime(0, 0, 0, $demand->getMonth(), $demand->getDay(), $demand->getYear());
                    $end = mktime(23, 59, 59, $demand->getMonth(), $demand->getDay(), $demand->getYear());
                } else {
                    $begin = mktime(0, 0, 0, $demand->getMonth(), 1, $demand->getYear());
                    $end = mktime(23, 59, 59, ($demand->getMonth() + 1), 0, $demand->getYear());
                }

                $dateConstraints = $this->getDateConstraint($query, $dateField, $begin, $end);
                $constraints['datetime'] = $query->logicalOr($dateConstraints);
            } elseif ($demand->getTimeRestriction() || $demand->getTimeRestrictionHigh()) {
                // Time restriction low
                $begin = $demand->getTimeRestriction() ?
                    ConstraintHelper::getTimeRestrictionLow($demand->getTimeRestriction()) : 0;

                // Time restriction high
                $end = $demand->getTimeRestrictionHigh() ?
                    ConstraintHelper::getTimeRestrictionHigh($demand->getTimeRestrictionHigh()) : 0;

                $dateField = $demand->getDateField();
                $dateConstraints         = $this->getDateConstraint($query, $dateField, $begin, $end);
                $constraints['datetime'] = $query->logicalOr($dateConstraints);
            }

            $organizers = $demand->getOrganizers();
            if (!empty($organizers)) {
                $constraints[] = $query->in('organizer', $organizers);
            }

            $locations = $demand->getLocations();
            if (!empty($locations)) {
                $constraints[] = $query->in('location', $locations);
            }

            // Time start
            $converted = strtotime($demand->getSearchDateFrom());
            if ($converted) {
                $constraints[] = $query->greaterThanOrEqual('datetime', $converted);
            }
            // Time end
            $converted = strtotime($demand->getSearchDateTo());
            if ($converted) {
                // add 23h59min to include program of that day
                $converted += 86350;
                $constraints[] = $query->lessThanOrEqual('datetime', $converted);
            }
        }
    }

    /**
     * @param QueryInterface $query
     * @param string $dateField
     * @param int $begin
     * @param int $end
     * @return array
     */
    protected function getDateConstraint(\TYPO3\CMS\Extbase\Persistence\QueryInterface $query, $dateField, $begin, $end)
    {
        $noEndDate = array();
        $begin ? ($noEndDate[] = $query->greaterThanOrEqual($dateField, $begin)) : null;
        $end ? ($noEndDate[] = $query->lessThanOrEqual($dateField, $end)) : null;

        $eventsWithNoEndDate = array(
            $query->logicalAnd($noEndDate)
        );

        // event inside a month, e.g. 3.3 - 8.3
        $insideMonth = array();
        $begin ? ($insideMonth[] = $query->greaterThanOrEqual($dateField, $begin)) : null;
        $end ? ($insideMonth[] = $query->lessThanOrEqual($dateField, $end)) : null;
        $end ? ($insideMonth[] = $query->lessThanOrEqual('eventEnd', $end)) : null;

        // event expanded from month before to month after
        $expandedMonthBeforeToMonthAfter = array();
        $begin ? ($expandedMonthBeforeToMonthAfter[] = $query->lessThanOrEqual($dateField, $begin)) : null;
        $end ? ($expandedMonthBeforeToMonthAfter[] = $query->greaterThanOrEqual('eventEnd', $end)) : null;

        $eventsWithEndDate = array(
            $query->logicalAnd($insideMonth),
            $query->logicalAnd($expandedMonthBeforeToMonthAfter)
        );

        // event from month before to mid of month
        if ($begin) {
            $eventsWithEndDate[] = $query->logicalAnd(
                $query->lessThanOrEqual($dateField, $begin),
                $query->greaterThanOrEqual('eventEnd', $begin)
            );
        }

        // event from mid month to next month
        if ($end) {
            $eventsWithEndDate[] = $query->logicalAnd(
                $query->lessThanOrEqual($dateField, $end),
                $query->greaterThanOrEqual('eventEnd', $end)
            );
        }

        $dateConstraints = array(
            $query->logicalAnd($eventsWithNoEndDate),
            $query->logicalOr($eventsWithEndDate)
        );
        return $dateConstraints;
    }
}

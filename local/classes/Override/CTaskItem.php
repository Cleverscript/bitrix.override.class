<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage tasks
 * @copyright 2001-2013 Bitrix
 *
 * @deprecated
 */

namespace classes\Override;

use Bitrix;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\ActionFailedException;
use Bitrix\Tasks\ActionNotAllowedException;
use Bitrix\Tasks\ActionRestrictedException;
use Bitrix\Tasks\CheckList\Internals\CheckList;
use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;
use Bitrix\Tasks\CheckList\Template\TemplateCheckListFacade;
use Bitrix\Tasks\Comments\Task\CommentPoster;
use Bitrix\Tasks\Flow\Access\FlowAccessController;
use Bitrix\Tasks\Flow\Access\FlowAction;
use Bitrix\Tasks\Helper\Analytics;
use Bitrix\Tasks\Integration;
use Bitrix\Tasks\Integration\Disk\Rest\Attachment;
use Bitrix\Tasks\Integration\Rest\Task\UserField;
use Bitrix\Tasks\Internals\Log\Logger;
use Bitrix\Tasks\Internals\Task\FavoriteTable;
use Bitrix\Tasks\Internals\Task\MetaStatus;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Internals\Task\TimeUnitType;
use Bitrix\Tasks\Task\DependenceTable;
use Bitrix\Tasks\Util\Calendar;
use Bitrix\Tasks\Util\Type\DateTime;
use Bitrix\Tasks\Util\User;
use CTaskItemInterface;
use CTaskMembers;
use CTasks;
use CTaskTags;
use TasksException;

Loc::loadMessages(__FILE__);

final class CTaskItem extends CTaskItem implements CTaskItemInterface
{
    public static function fetchListArray(
        $userId,
        $arOrder,
        $arFilter,
        $arParams = array(),
        $arSelect = array(),
        array $arGroup = array()
    ) {
        log($arFilter);

        $arFilter['UF_SHOW'] = true;

        //echo '<pre>';
        //print_r($arFilter);
        //echo '</pre>';
        //die();

        $arItemsData = array();
        $rsData = null;

        try {
            $arParamsOut = array(
                'USER_ID' => $userId,
                'bIgnoreErrors' => true,        // don't die on SQL errors
                'PERMISSION_CHECK_VERSION' => 2, // new sql code to check permissions
            );

            if (array_key_exists('TARGET_USER_ID', $arParams)) {
                $arParamsOut['TARGET_USER_ID'] = $arParams['TARGET_USER_ID'];
            }
            if (isset($arParams['SORTING_GROUP_ID'])) {
                $arParamsOut['SORTING_GROUP_ID'] = $arParams['SORTING_GROUP_ID'];
            }
            if (isset($arParams['MAKE_ACCESS_FILTER'])) {
                $arParamsOut['MAKE_ACCESS_FILTER'] = $arParams['MAKE_ACCESS_FILTER'];
            }

            if (isset($arParams['nPageTop'])) {
                $arParamsOut['nPageTop'] = $arParams['nPageTop'];
            } elseif (isset($arParams['NAV_PARAMS'])) {
                $arParamsOut['NAV_PARAMS'] = $arParams['NAV_PARAMS'];
            }

            $arParamsOut['DISTINCT'] = $arParams['DISTINCT'] ?? false;

            $arFilter['CHECK_PERMISSIONS'] = 'Y';    // Always check permissions

            if (!empty($arSelect) && (!isset($arParams['USE_MINIMAL_SELECT_LEGACY']) || $arParams['USE_MINIMAL_SELECT_LEGACY'] != 'N')) {
                $arSelect = array_merge(
                    $arSelect,
                    static::getMinimalSelectLegacy()
                );
            }

            $arTasksIDs = array();
            $rsData = CTasks::getList($arOrder, $arFilter, $arSelect, $arParamsOut, $arGroup);

            if (!is_object($rsData)) {
                throw new TasksException();
            }

            while ($arData = $rsData->fetch()) {
                $taskId = (int)$arData['ID'];
                $arTasksIDs[] = $taskId;

                if (in_array('AUDITORS', $arSelect) || in_array('*', $arSelect)) {
                    $arData['AUDITORS'] = [];
                }
                if (in_array('ACCOMPLICES', $arSelect) || in_array('*', $arSelect)) {
                    $arData['ACCOMPLICES'] = [];
                }

                if (array_key_exists('TITLE', $arData)) {
                    $arData['TITLE'] = \Bitrix\Main\Text\Emoji::decode($arData['TITLE']);
                }
                if (array_key_exists('DESCRIPTION', $arData) && $arData['DESCRIPTION'] !== '') {
                    $arData['DESCRIPTION'] = \Bitrix\Main\Text\Emoji::decode($arData['DESCRIPTION']);
                }

                $arItemsData[$taskId] = $arData;
            }

            if (is_array($arTasksIDs) && !empty($arTasksIDs)) {
                if (in_array('NEW_COMMENTS_COUNT', $arSelect, true)) {
                    $newComments = Bitrix\Tasks\Internals\Counter::getInstance((int)$userId)->getCommentsCount(
                        $arTasksIDs
                    );
                    foreach ($newComments as $taskId => $commentsCount) {
                        $arItemsData[$taskId]['NEW_COMMENTS_COUNT'] = $commentsCount;
                    }
                }
                if (in_array('AUDITORS', $arSelect) || in_array('ACCOMPLICES', $arSelect) || in_array('*', $arSelect)) {
                    // fill ACCOMPLICES and AUDITORS
                    $rsMembers = CTaskMembers::GetList(array(), array('TASK_ID' => $arTasksIDs));

                    if (!is_object($rsMembers)) {
                        throw new TasksException();
                    }

                    while ($arMember = $rsMembers->fetch()) {
                        $taskId = (int)$arMember['TASK_ID'];

                        if (in_array($taskId, $arTasksIDs, true)) {
                            if ($arMember['TYPE'] === 'A' && (in_array('ACCOMPLICES', $arSelect) || in_array(
                                        '*',
                                        $arSelect
                                    ))) {
                                $arItemsData[$taskId]['ACCOMPLICES'][] = $arMember['USER_ID'];
                            } elseif ($arMember['TYPE'] === 'U' && (in_array('AUDITORS', $arSelect) || in_array(
                                        '*',
                                        $arSelect
                                    ))) {
                                $arItemsData[$taskId]['AUDITORS'][] = $arMember['USER_ID'];
                            }
                        }
                    }
                }

                // fill tags
                if (isset($arParams['LOAD_TAGS']) && $arParams['LOAD_TAGS']) {
                    foreach ($arTasksIDs as $taskId) {
                        $arItemsData[$taskId]['TAGS'] = array();
                    }

                    $rsTags = CTaskTags::getList(array(), array('TASK_ID' => $arTasksIDs));

                    if (!is_object($rsTags)) {
                        throw new TasksException();
                    }

                    while ($arTag = $rsTags->fetch()) {
                        $taskId = (int)$arTag['TASK_ID'];

                        if (in_array($taskId, $arTasksIDs, true)) {
                            $arItemsData[$taskId]['TAGS'][] = $arTag['NAME'];
                        }
                    }
                }

                // fill parameters
                if (isset($arParams['LOAD_PARAMETERS'])) {
                    $res = \Bitrix\Tasks\Internals\Task\ParameterTable::getList(array(
                        'filter' => array(
                            'TASK_ID' => $arTasksIDs
                        )
                    ));
                    while ($paramItem = $res->fetch()) {
                        $arItemsData[$paramItem['TASK_ID']]['SE_PARAMETER'][] = $paramItem;
                    }
                }
            }
        } catch (Exception $e) {
            $message = '[0xa819f6f1] probably SQL error at ' . $e->getFile() . ':' . $e->getLine(
                ) . '. ' . $e->getMessage();
            Logger::log($message, 'TASKS_TASK_FETCH_LIST');
            throw new TasksException(
                $e->getMessage(),
                TasksException::TE_SQL_ERROR
                | TasksException::TE_ACTION_FAILED_TO_BE_PROCESSED
            );
        }

        return array($arItemsData, $rsData);
    }
}

<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\MZI;

use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\IssueField;
use JiraRestApi\JiraException;
use Magento\MZI\Util\LoggingUtil;

class CreateIssue
{
    /**
     * Label for created issue
     */
    const CREATE_LABEL = 'mzi_created';

    /**
     * Note for test creation
     */
    const NOTE_FOR_CREATE = "\nCreated by mftf zephyr integration from test: ";

    /**
     * Test containing all information for issue to be created from MFTF annotations
     *
     * @var array
     */
    private $test;

    /**
     * CreateIssue constructor
     *
     * @param $id
     */
    public function __construct($id)
    {
        $this->test = $this->defaultMissingFields($id);
    }

    /**
     * Creates an issue in Zephyr from test data
     * Will transition new issue to Automated status
     * If test is skipped, will call skip transition and issuelink functions
     *
     * @param string $testName
     * @param array $test
     *
     * @return String
     * @throws \Exception
     */
    public function createIssueREST($testName, array $test)
    {
        $test = $this->defaultMissingFields($test);
        $issueField = new IssueField(null, null, __DIR__  . '/../../../');
        /** Created fields:
         *
         * - Required fields:
         * project, issueType, summary, components, severity, test type, release line
         *
         * - Additional fields:
         * stories
         * status
         * label
         * description + test name
        */

        $issueField->setProjectKey(ZephyrIntegrationManager::$project);
        $issueField->setSummary($test['title'][0]);
        $issueField->setIssueType('Test');
        $issueField->setDescription($test['description'][0] . self::NOTE_FOR_CREATE . $testName . "\n");
        $issueField->addComponents($this->getZephyrComponentName($test['features'][0]));

        if (isset($test['stories'])) {
            $stories = $test['stories'][0];
            if (!empty($stories)) {
                $issueField->addCustomField('customfield_14364', $test['stories'][0]);
            }
        }
        $issueField->addCustomField('customfield_12720', ['value' => $test['severity'][0]]);
        $issueField->addCustomField('customfield_13324', ['value' => 'MFTF Test']); // Test Type
        $issueField->addCustomField('customfield_14121', ['value' => $test['releaseLine'][0]]); // Release Line
        $issueField->addLabel(self::CREATE_LABEL . ZephyrIntegrationManager::$timestamp);

        $key = '';
        $logMessage = "\nCreating Test With Data:\nSummary: " . $issueField->summary . "\nDescription: " . $issueField->description;
        $time_start = microtime(true);
        if (!ZephyrIntegrationManager::$dryRun) {
            try {
                $issueService = new IssueService(null, null, __DIR__  . '/../../../');
                $ret = $issueService->create($issueField);
                $key = $ret->key;
                $time_end = microtime(true);
                $time = $time_end - $time_start;
                $logMessage .= "Created New Test $key In $time Seconds\n";
            } catch (JiraException $e) {
                print("\nException Occurs In JIRA create(). " . $e->getMessage());
                LoggingUtil::getInstance()->getLogger(CreateIssue::class)->info(
                    "\nException Occurs In JIRA create(). " . $e->getMessage()
                );
                $success = false;
                for ($i = 0; $i < ZephyrIntegrationManager::$retryCount; $i++) {
                    print("\nRetry # $i...\n");
                    LoggingUtil::getInstance()->getLogger(CreateIssue::class)->info("\nRetry # $i...\n");
                    try {
                        $issueService = new IssueService(null, null, __DIR__  . '/../../../');
                        $ret = $issueService->create($issueField);
                        $key = $ret->key;
                        $time_end = microtime(true);
                        $time = $time_end - $time_start;
                        $logMessage .= "Created New Test $key In $time Seconds\n";
                        $success = true;
                        break;
                    } catch (JiraException $e2) {
                        $e = $e2;
                    }
                }
                if (!$success) {
                    print(
                        "While Processing "
                        . $logMessage
                        . "After "
                        . ZephyrIntegrationManager::$retryCount
                        . " Tries, Still Getting JIRA Exception: "
                        . $e->getMessage()
                    );
                    LoggingUtil::getInstance()->getLogger(CreateIssue::class)->info(
                        "While Processing "
                        . $logMessage
                        . "After "
                        . ZephyrIntegrationManager::$retryCount
                        . " Tries, Still Getting JIRA Exception: "
                        . $e->getMessage()
                    );
                    print("\nExiting With Code 1\n");
                    exit(1);
                }
            }
        } else {
            $logMessage = "\nDry Run... $logMessage" . "Completed!\n\n";
        }
        print($logMessage);
        LoggingUtil::getInstance()->getLogger(CreateIssue::class)->info($logMessage);

        // Transition this newly created issue from "Open" to "AUTOMATED" or "Skipped"
        $transitionExecutor = new TransitionIssue(null, null, __DIR__  . '/../../../');
        $transitionExecutor->statusTransitionToAutomated($key, 'Open');
        if (isset($test['skip'])) {
            $test += ['key' => $key];
            $transitionExecutor->oneStepStatusTransition($key, 'Skipped');
            $updateIssue = new UpdateIssue(null, null, __DIR__  . '/../../../');
            $updateIssue->skipTestLinkIssue($test);
        }
        return $key;
    }

    /**
     * For any missing required fields on a test to be created,
     * sets default fields
     *
     * @param $test
     * @return array
     */
    private function defaultMissingFields($test)
    {
        if (!(isset($test['stories']))) {
            $test['stories'][0] = '';
        }
        if (!(isset($test['severity']))) {
            $test['severity'][0] = '4-Minor';
        }
        if (!(isset($test['title']))) {
            $test['title'][0] = 'NO TITLE';
        }
        if (!(isset($test['description']))) {
            $test['description'][0] = 'NO DESCRIPTION';
        }
        return $test;
    }

    /**
     * @param string $feature
     * @return string
     * @throws \Exception
     */
    private function getZephyrComponentName($feature)
    {
        $components = GetZephyr::getInstance()->getComponentsForProject();
        foreach ($components as $component) {
            if (stripos($component, 'Module/ ' . $feature) !== false) {
                return $component;
            }
        }
        foreach ($components as $component) {
            if (stripos($feature, substr($component, 8)) !== false) {
                return $component;
            }
        }
        return 'Module/ Backend';
    }
}
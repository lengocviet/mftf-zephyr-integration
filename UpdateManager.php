<?php
/**
 * Created by PhpStorm.
 * User: terskine
 * Date: 9/16/18
 * Time: 8:12 PM
 */

namespace Magento\JZI;


class UpdateManager
{
    public static $updateManager;

    public static function getInstance() {
        if (!self::$updateManager) {
            self::$updateManager = new UpdateManager();
        }

        return self::$updateManager;
    }

    public function performUpdateOperations($updates, $skipped) {
        //loop - test for if skipped. if skipped, pass to skipCreate, if not, vanillaCreate
        foreach ($updates as $id) {
            if (array_key_exists($id, $skipped)) {
                $this->updateSkipped($id);
            }
            else {
                $updateIssue = new UpdateIssue($id);
                $reponse = $updateIssue::updateIssueREST($id);
            }
        }
    }

    public function updateSkipped($id) {
        $updateIssue = new UpdateIssue($id);
        $response = $updateIssue::updateSkippedTest($id);
        return $response;

    }


}
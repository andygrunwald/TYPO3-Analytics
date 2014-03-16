<?php
/**
 * This file is part of the TYPO3-Analytics package.
 *
 * (c) Andreas Grunwald <andygrunwald@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TYPO3Analysis\Consumer\Download;

use TYPO3Analysis\Consumer\ConsumerAbstract;
use TYPO3Analysis\Helper\File;

class HTTP extends ConsumerAbstract
{

    /**
     * Gets a description of the consumer
     *
     * @return string
     */
    public function getDescription()
    {
        return 'Downloads a HTTP resource.';
    }

    /**
     * Initialize the consumer.
     * Sets the queue and routing key
     *
     * @return void
     */
    public function initialize()
    {
        $this->setQueue('download.http');
        $this->setRouting('download.http');
    }

    /**
     * The logic of the consumer
     *
     * @param \stdClass $message
     * @return void
     */
    public function process($message)
    {
        $this->setMessage($message);
        $messageData = json_decode($message->body);

        $this->getLogger()->info('Receiving message', (array)$messageData);

        $record = $this->getVersionFromDatabase($messageData->versionId);
        $context = array('versionId' => $messageData->versionId);

        // If the record does not exists in the database exit here
        if ($record === false) {
            $this->getLogger()->info('Record does not exist in version table', $context);
            $this->acknowledgeMessage($message);
            return;
        }

        // If the file has already been downloaded exit here
        if (isset($record['downloaded']) === true && $record['downloaded']) {
            $this->getLogger()->info('Record marked as already downloaded', $context);
            $this->acknowledgeMessage($message);
            return;
        }

        $targetTempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        // @todo find a better way for the filename ... Download-Prefix in project config?
        // in $messageData->Project you can find the project
        $fileName = 'typo3_' . $record['version'] . '.tar.gz';
        $downloadFile = new File($targetTempDir . $fileName);

        $config = $this->getConfig();
        $projectConfig = $config['Projects'][$messageData->project];
        $targetDir = rtrim($projectConfig['ReleasesPath'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $targetFile = new File($targetDir . $fileName);

        // If the file already there do not download it again
        if ($targetFile->exists() === true && $record['checksum_tar_md5']
            && $targetFile->getMd5OfFile() === $record['checksum_tar_md5']
        ) {
            $context = array(
                'targetFile' => $targetFile->getFile()
            );
            $this->getLogger()->info('File already exists', $context);
            $this->setVersionAsDownloadedInDatabase($record['id']);
            $this->acknowledgeMessage($message);
            $this->addFurtherMessageToQueue($messageData->project, $record['id'], $targetFile->getFile());
            $this->getLogger()->info('Finish processing message', (array)$messageData);
            return;
        }

        $context = array(
            'downloadUrl' => $record['url_tar'],
            'targetFile' => $downloadFile->getFile()
        );
        $this->getLogger()->info('Starting download', $context);


        $downloadResult = $downloadFile->download($record['url_tar'], $config['Various']['Downloads']['Timeout']);
        if (!$downloadResult) {
            $context = array(
                'file' => $record['url_tar'],
                'timeout' => $config['Various']['Downloads']['Timeout'],
            );
            $this->getLogger()->critical('Download of file failed', $context);
            $this->acknowledgeMessage($this->getMessage());
            return;
        }

        // If there is no file after download, exit here
        if ($downloadFile->exists() !== true) {
            $context = ['targetFile' => $downloadFile->getFile()];
            $this->getLogger()->critical('File does not exist after download', $context);
            $this->acknowledgeMessage($this->getMessage());
            return;
        }

        if (is_dir($targetDir) === false) {
            // TODO What the fuck? Was i drunken?!
            mkdir($targetDir, 0777, true);
        }

        $downloadFile->rename($targetFile->getFile());

        // If the hashes are not equal, exit here
        $md5Hash = $downloadFile->getMd5OfFile();
        if ($record['checksum_tar_md5'] && $md5Hash !== $record['checksum_tar_md5']) {
            $context = array(
                'targetFile' => $downloadFile->getFile(),
                'databaseHash' => $record['checksum_tar_md5'],
                'fileHash' => $md5Hash
            );
            $this->getLogger()->critical('Checksums for file are not equal', $context);
            $this->acknowledgeMessage($this->getMessage());
            return;
        }

        // Update the 'downloaded' flag in database
        $this->setVersionAsDownloadedInDatabase($record['id']);

        $this->acknowledgeMessage($message);

        // Adds new messages to queue: extract the file, get filesize or tar.gz file
        $this->addFurtherMessageToQueue($messageData->project, $record['id'], $downloadFile->getFile());

        $this->getLogger()->info('Finish processing message', (array)$messageData);
    }

    /**
     * Adds new messages to queue system to extract a tar.gz file and get the filesize of this file
     *
     * @param string $project
     * @param integer $id
     * @param string $file
     * @return void
     */
    private function addFurtherMessageToQueue($project, $id, $file)
    {
        $message = array(
            'project' => $project,
            'versionId' => $id,
            'filename' => $file
        );

        $this->getMessageQueue()->sendMessage($message, 'TYPO3', 'extract.targz', 'extract.targz');
        $this->getMessageQueue()->sendMessage($message, 'TYPO3', 'analysis.filesize', 'analysis.filesize');
    }

    /**
     * Receives a single version of the database
     *
     * @param integer $id
     * @return bool|array
     */
    private function getVersionFromDatabase($id)
    {
        $fields = array('id', 'version', 'checksum_tar_md5', 'url_tar', 'downloaded');
        $rows = $this->getDatabase()->getRecords($fields, 'versions', array('id' => $id), '', '', 1);

        $row = false;
        if (count($rows) === 1) {
            $row = array_shift($rows);
            unset($rows);
        }

        return $row;
    }

    /**
     * Updates a single version and sets them to 'downloaded'
     *
     * @param integer $id
     * @return void
     */
    private function setVersionAsDownloadedInDatabase($id)
    {
        $this->getDatabase()->updateRecord('versions', array('downloaded' => 1), array('id' => $id));
        $this->getLogger()->info('Set version as downloaded', array('versionId' => $id));
    }
}

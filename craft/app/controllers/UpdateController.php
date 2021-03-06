<?php
namespace Craft;

/**
 * Craft by Pixel & Tonic
 *
 * @package   Craft
 * @author    Pixel & Tonic, Inc.
 * @copyright Copyright (c) 2013, Pixel & Tonic, Inc.
 * @license   http://buildwithcraft.com/license Craft License Agreement
 * @link      http://buildwithcraft.com
 */

/**
 *
 */
class UpdateController extends BaseController
{
	protected $allowAnonymous = array('actionManualUpdate', 'actionPrepare', 'actionBackupDatabase', 'actionUpdateDatabase', 'actionCleanUp', 'actionRollback');

	// -------------------------------------------
	//  Auto Updates
	// -------------------------------------------

	/**
	 * Returns the available updates
	 */
	public function actionGetAvailableUpdates()
	{
		craft()->userSession->requirePermission('performUpdates');

		try
		{
			$updates = craft()->updates->getUpdates(true);
		}
		catch (EtException $e)
		{
			if ($e->getCode() == 10001)
			{
				$this->returnErrorJson($e->getMessage());
			}
		}

		if ($updates)
		{
			$this->returnJson($updates);
		}
		else
		{
			$this->returnErrorJson(Craft::t('Could not fetch available updates at this time.'));
		}
	}

	/**
	 * Returns the update info JSON.
	 */
	public function actionGetUpdates()
	{
		craft()->userSession->requirePermission('performUpdates');

		$this->requireAjaxRequest();

		$handle = craft()->request->getRequiredPost('handle');

		$return = array();
		$updateInfo = craft()->updates->getUpdates();

		if (!$updateInfo)
		{
			$this->returnErrorJson(Craft::t('There was a problem getting the latest update information.'));
		}

		try
		{
			switch ($handle)
			{
				case 'all':
				{
					// Craft first.
					$return[] = array('handle' => 'Craft', 'name' => 'Craft', 'version' => $updateInfo->app->latestVersion.'.'.$updateInfo->app->latestBuild, 'critical' => $updateInfo->app->criticalUpdateAvailable, 'releaseDate' => $updateInfo->app->latestDate->getTimestamp());

					// Plugins
					if ($updateInfo->plugins !== null)
					{
						foreach ($updateInfo->plugins as $plugin)
						{
							if ($plugin->status == PluginVersionUpdateStatus::UpdateAvailable && count($plugin->releases) > 0)
							{
								$return[] = array('handle' => $plugin->class, 'name' => $plugin->displayName, 'version' => $plugin->latestVersion, 'critical' => $plugin->criticalUpdateAvailable, 'releaseDate' => $plugin->latestDate->getTimestamp());
							}
						}
					}

					break;
				}

				case 'craft':
				{
					$return[] = array('handle' => 'Craft', 'name' => 'Craft', 'version' => $updateInfo->app->latestVersion.'.'.$updateInfo->app->latestBuild, 'critical' => $updateInfo->app->criticalUpdateAvailable, 'releaseDate' => $updateInfo->app->latestDate->getTimestamp());
					break;
				}

				// We assume it's a plugin handle.
				default:
				{
					if (!empty($updateInfo->plugins))
					{
						if (isset($updateInfo->plugins[$handle]) && $updateInfo->plugins[$handle]->status == PluginVersionUpdateStatus::UpdateAvailable && count($updateInfo->plugins[$handle]->releases) > 0)
						{
							$return[] = array('handle' => $updateInfo->plugins[$handle]->handle, 'name' => $updateInfo->plugins[$handle]->displayName, 'version' => $updateInfo->plugins[$handle]->latestVersion, 'critical' => $updateInfo->plugins[$handle]->criticalUpdateAvailable, 'releaseDate' => $updateInfo->plugins[$handle]->latestDate->getTimestamp());
						}
						else
						{
							$this->returnErrorJson(Craft::t("Could not find any update information for the plugin with handle “{handle}”.", array('handle' => $handle)));
						}
					}
					else
					{
						$this->returnErrorJson(Craft::t("Could not find any update information for the plugin with handle “{handle}”.", array('handle' => $handle)));
					}
				}
			}

			$r = array('success' => true, 'updateInfo' => $return);
			$this->returnJson($r);
		}
		catch (\Exception $e)
		{
			$this->returnErrorJson($e->getMessage());
		}
	}

	// -------------------------------------------
	//  Manual Updates
	// -------------------------------------------

	/**
	 *
	 */
	public function actionPrepare()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$data = craft()->request->getRequiredPost('data');

		$manual = false;
		if (!$this->_isManualUpdate($data))
		{
			// If it's not a manual update, make sure they have auto-update permissions.
			craft()->userSession->requirePermission('performUpdates');
		}
		else
		{
			$manual = true;
		}

		$return = craft()->updates->prepareUpdate($manual, $data['handle']);

		if (!$return['success'])
		{
			$this->returnJson(array('error' => $return['message']));
		}

		if ($manual)
		{
			$this->returnJson(array('success' => true, 'nextStatus' => Craft::t('Backing Up Database…'), 'nextAction' => 'update/backupDatabase', 'data' => $data));
		}
		else
		{
			$data['md5'] = $return['md5'];
			$this->returnJson(array('success' => true, 'nextStatus' => Craft::t('Downloading Update…'), 'nextAction' => 'update/processDownload', 'data' => $data));
		}

	}

	/**
	 *
	 */
	public function actionProcessDownload()
	{
		// This method should never be called in a manual update.
		craft()->userSession->requirePermission('performUpdates');

		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$data = craft()->request->getRequiredPost('data');

		$return = craft()->updates->processUpdateDownload($data['md5']);
		if (!$return['success'])
		{
			$this->returnJson(array('error' => $return['message']));
		}

		unset($return['success']);

		$this->returnJson(array('success' => true, 'nextStatus' => Craft::t('Backing Up Files…'), 'nextAction' => 'update/backupFiles', 'data' => $return));
	}

	/**
	 *
	 */
	public function actionBackupFiles()
	{
		// This method should never be called in a manual update.
		craft()->userSession->requirePermission('performUpdates');

		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$data = craft()->request->getRequiredPost('data');

		$return = craft()->updates->backupFiles($data['uid']);
		if (!$return['success'])
		{
			$this->returnJson(array('error' => $return['message']));
		}

		$this->returnJson(array('success' => true, 'nextStatus' => Craft::t('Updating Files…'), 'nextAction' => 'update/updateFiles', 'data' => $data));
	}

	/**
	 *
	 */
	public function actionUpdateFiles()
	{
		// This method should never be called in a manual update.
		craft()->userSession->requirePermission('performUpdates');

		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$data = craft()->request->getRequiredPost('data');

		$return = craft()->updates->updateFiles($data['uid']);
		if (!$return['success'])
		{
			$this->returnJson(array('error' => $return['message']));
		}

		$this->returnJson(array('success' => true, 'nextStatus' => Craft::t('Backing Up Database…'), 'nextAction' => 'update/backupDatabase', 'data' => $data));
	}

	/**
	 *
	 */
	public function actionBackupDatabase()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$data = craft()->request->getRequiredPost('data');

		if ($this->_isManualUpdate($data))
		{
			$uid = false;
		}
		else
		{
			// If it's not a manual update, make sure they have auto-update permissions.
			craft()->userSession->requirePermission('performUpdates');

			$uid = $data['uid'];
		}

		if (craft()->config->get('backupDbOnUpdate'))
		{
			$return = craft()->updates->backupDatabase($uid);
			if (!$return['success'])
			{
				$this->returnJson(array('error' => $return['message']));
			}

			if (isset($return['dbBackupPath']))
			{
				$data['dbBackupPath'] = $return['dbBackupPath'];
			}
		}

		$this->returnJson(array('success' => true, 'nextStatus' => Craft::t('Updating Database…'), 'nextAction' => 'update/updateDatabase', 'data' => $data));
	}

	/**
	 *
	 */
	public function actionUpdateDatabase()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$data = craft()->request->getRequiredPost('data');

		if ($this->_isManualUpdate($data))
		{
			$uid = false;
		}
		else
		{
			// If it's not a manual update, make sure they have auto-update permissions.
			craft()->userSession->requirePermission('performUpdates');

			$uid = $data['uid'];
		}

		$handle = $this->_getFixedHandle($data);

		if (isset($data['dbBackupPath']))
		{
			$return = craft()->updates->updateDatabase($uid, $handle, $data['dbBackupPath']);
		}
		else
		{
			$return = craft()->updates->updateDatabase($uid, $handle);
		}

		if (!$return['success'])
		{
			$this->returnJson(array('error' => $return['message']));
		}

		$this->returnJson(array('success' => true, 'nextStatus' => Craft::t('Cleaning Up…'), 'nextAction' => 'update/cleanUp', 'data' => $data));
	}

	/**
	 * Performs maintenance and clean up tasks after an update.
	 */
	public function actionCleanUp()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$data = craft()->request->getRequiredPost('data');

		if ($this->_isManualUpdate($data))
		{
			$uid = false;
		}
		else
		{
			// If it's not a manual update, make sure they have auto-update permissions.
			craft()->userSession->requirePermission('performUpdates');

			$uid = $data['uid'];
		}

		$handle = $this->_getFixedHandle($data);

		$oldVersion = false;

		// Grab the old version from the manifest data before we nuke it.
		$manifestData = UpdateHelper::getManifestData(UpdateHelper::getUnzipFolderFromUID($uid));

		if ($manifestData)
		{
			$oldVersion = UpdateHelper::getLocalVersionFromManifest($manifestData);
		}

		$return = craft()->updates->updateCleanUp($uid, $handle);
		if (!$return['success'])
		{
			$this->returnJson(array('error' => $return['message']));
		}

		if ($oldVersion && version_compare($oldVersion, Craft::getVersion(), '<'))
		{
			$returnUrl = UrlHelper::getUrl('whats-new');
		}
		else
		{
			$returnUrl = craft()->userSession->getReturnUrl();
		}

		$this->returnJson(array(
			'success' => true,
			'finished' => true,
			'returnUrl' => $returnUrl
		));
	}

	/**
	 *
	 */
	public function actionRollback()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$data = craft()->request->getRequiredPost('data');

		if ($this->_isManualUpdate($data))
		{
			$uid = false;
		}
		else
		{
			// If it's not a manual update, make sure they have auto-update permissions.
			craft()->userSession->requirePermission('performUpdates');

			$uid = $data['uid'];
		}

		if (isset($data['dbBackupPath']))
		{
			$return = craft()->updates->rollbackUpdate($uid, $data['dbBackupPath']);
		}
		else
		{
			$return = craft()->updates->rollbackUpdate($uid);
		}

		if (!$return['success'])
		{
			$this->returnJson(array('error' => $return['message']));
		}

		$this->returnJson(array('success' => true, 'finished' => true));
	}

	/**
	 * @param $data
	 * @return bool
	 */
	private function _isManualUpdate($data)
	{
		if (isset($data['manualUpdate']) && $data['manualUpdate'] == 1)
		{
			return true;
		}

		return false;
	}

	/**
	 * @param $data
	 * @return string
	 */
	private function _getFixedHandle($data)
	{

		if (!isset($data['handle']))
		{
			return 'craft';
		}
		else
		{
			return $data['handle'];
		}
	}
}

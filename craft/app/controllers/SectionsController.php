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
 * Handles section management tasks
 */
class SectionsController extends BaseController
{
	/**
	 * Init
	 */
	public function init()
	{
		// All section actions require an admin
		craft()->userSession->requireAdmin();
	}

	/**
	 * Sections index
	 */
	public function actionIndex(array $variables = array())
	{
		$variables['sections'] = craft()->sections->getAllSections();

		// Can new sections be added?
		if (!Craft::hasPackage(CraftPackage::PublishPro))
		{
			$variables['maxSections'] = 0;

			foreach (craft()->sections->typeLimits as $limit)
			{
				$variables['maxSections'] += $limit;
			}
		}

		$this->renderTemplate('settings/sections/index', $variables);
	}

	/**
	 * Edit a section.
	 *
	 * @param array $variables
	 * @throws HttpException
	 */
	public function actionEditSection(array $variables = array())
	{
		$variables['brandNewSection'] = false;

		if (!empty($variables['sectionId']))
		{
			if (empty($variables['section']))
			{
				$variables['section'] = craft()->sections->getSectionById($variables['sectionId']);

				if (!$variables['section'])
				{
					throw new HttpException(404);
				}
			}

			$variables['title'] = $variables['section']->name;
		}
		else
		{
			if (empty($variables['section']))
			{
				$variables['section'] = new SectionModel();
				$variables['brandNewSection'] = true;
			}

			$variables['title'] = Craft::t('Create a new section');
		}

		$types = array(SectionType::Single, SectionType::Channel, SectionType::Structure);
		$variables['typeOptions'] = array();

		foreach ($types as $type)
		{
			$allowed = (($variables['section']->id && $variables['section']->type == $type) || craft()->sections->canHaveMore($type));
			$variables['canBe'.ucfirst($type)] = $allowed;

			if ($allowed)
			{
				$variables['typeOptions'][$type] = Craft::t(ucfirst($type));
			}
		}

		if (!$variables['typeOptions'])
		{
			throw new Exception(Craft::t('Publish Pro is required to create any additional sections.'));
		}

		if (!$variables['section']->type)
		{
			if ($variables['canBeChannel'])
			{
				$variables['section']->type = SectionType::Channel;
			}
			else
			{
				$variables['section']->type = SectionType::Single;
			}
		}

		$variables['canBeHomepage']  = (
			($variables['section']->id && $variables['section']->isHomepage()) ||
			($variables['canBeSingle'] && !craft()->sections->doesHomepageExist())
		);

		$variables['crumbs'] = array(
			array('label' => Craft::t('Settings'), 'url' => UrlHelper::getUrl('settings')),
			array('label' => Craft::t('Sections'), 'url' => UrlHelper::getUrl('settings/sections')),
		);

		$this->renderTemplate('settings/sections/_edit', $variables);
	}

	/**
	 * Saves a section
	 */
	public function actionSaveSection()
	{
		$this->requirePostRequest();

		$section = new SectionModel();

		// Shared attributes
		$section->id         = craft()->request->getPost('sectionId');
		$section->name       = craft()->request->getPost('name');
		$section->handle     = craft()->request->getPost('handle');
		$section->type       = craft()->request->getPost('type');

		// Type-specific attributes
		$allTypeSettings = craft()->request->getPost('types');
		$typeSettings = $allTypeSettings[$section->type];

		$section->hasUrls  = (isset($typeSettings['hasUrls']) ? (bool) $typeSettings['hasUrls'] : true);
		$section->template = (isset($typeSettings['template']) ? $typeSettings['template'] : null);
		$section->maxDepth = (!empty($typeSettings['maxDepth']) ? $typeSettings['maxDepth'] : null);

		// Locale-specific attributes
		$locales = array();

		if (Craft::hasPackage(CraftPackage::Localize))
		{
			$localeIds = craft()->request->getPost('locales');
		}
		else
		{
			$primaryLocaleId = craft()->i18n->getPrimarySiteLocaleId();
			$localeIds = array($primaryLocaleId);
		}

		$isHomepage = ($section->type == SectionType::Single && !empty($typeSettings['homepage']));

		foreach ($localeIds as $localeId)
		{
			if ($isHomepage)
			{
				$urlFormat = '__home__';
				$nestedUrlFormat = null;
			}
			else
			{
				$urlFormat = (isset($typeSettings['urlFormat'][$localeId]) ? trim($typeSettings['urlFormat'][$localeId], '/') : null);
				$nestedUrlFormat = (isset($typeSettings['nestedUrlFormat'][$localeId]) ? trim($typeSettings['nestedUrlFormat'][$localeId], '/') : null);
			}

			$locales[$localeId] = new SectionLocaleModel(array(
				'locale'          => $localeId,
				'urlFormat'       => $urlFormat,
				'nestedUrlFormat' => $nestedUrlFormat,
			));
		}

		$section->setLocales($locales);

		// Save it
		if (craft()->sections->saveSection($section))
		{
			craft()->userSession->setNotice(Craft::t('Section saved.'));

			// TODO: Remove for 2.0
			if (isset($_POST['redirect']) && mb_strpos($_POST['redirect'], '{sectionId}') !== false)
			{
				Craft::log('The {sectionId} token within the ‘redirect’ param on sections/saveSection requests has been deprecated. Use {id} instead.', LogLevel::Warning);
				$_POST['redirect'] = str_replace('{sectionId}', '{id}', $_POST['redirect']);
			}

			$this->redirectToPostedUrl($section);
		}
		else
		{
			craft()->userSession->setError(Craft::t('Couldn’t save section.'));
		}

		// Send the section back to the template
		craft()->urlManager->setRouteVariables(array(
			'section' => $section
		));
	}

	/**
	 * Deletes a section.
	 */
	public function actionDeleteSection()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$sectionId = craft()->request->getRequiredPost('id');

		craft()->sections->deleteSectionById($sectionId);
		$this->returnJson(array('success' => true));
	}

	// Entry Types

	/**
	 * Entry types index
	 *
	 * @param array $variables
	 * @throws HttpException
	 */
	public function actionEntryTypesIndex(array $variables = array())
	{
		if (empty($variables['sectionId']))
		{
			throw new HttpException(400);
		}

		$variables['section'] = craft()->sections->getSectionById($variables['sectionId']);

		if (!$variables['section'])
		{
			throw new HttpException(404);
		}

		$variables['crumbs'] = array(
			array('label' => Craft::t('Settings'), 'url' => UrlHelper::getUrl('settings')),
			array('label' => Craft::t('Sections'), 'url' => UrlHelper::getUrl('settings/sections')),
			array('label' => $variables['section']->name, 'url' => UrlHelper::getUrl('settings/sections/'.$variables['section']->id)),
		);

		$variables['title'] = Craft::t('{section} Entry Types', array('section' => $variables['section']->name));

		$this->renderTemplate('settings/sections/_entrytypes/index', $variables);
	}

	/**
	 * Edit an entry type
	 *
	 * @param array $variables
	 * @throws HttpException
	 */
	public function actionEditEntryType(array $variables = array())
	{
		if (empty($variables['sectionId']))
		{
			throw new HttpException(400);
		}

		$variables['section'] = craft()->sections->getSectionById($variables['sectionId']);

		if (!$variables['section'])
		{
			throw new HttpException(404);
		}

		if (!empty($variables['entryTypeId']))
		{
			if (empty($variables['entryType']))
			{
				$variables['entryType'] = craft()->sections->getEntryTypeById($variables['entryTypeId']);

				if (!$variables['entryType'] || $variables['entryType']->sectionId != $variables['section']->id)
				{
					throw new HttpException(404);
				}
			}

			$variables['title'] = $variables['entryType']->name;
		}
		else
		{
			if (empty($variables['entryType']))
			{
				$variables['entryType'] = new EntryTypeModel();
				$variables['entryType']->sectionId = $variables['section']->id;
			}

			$variables['title'] = Craft::t('Create a new {section} entry type', array('section' => $variables['section']->name));
		}

		$variables['crumbs'] = array(
			array('label' => Craft::t('Settings'), 'url' => UrlHelper::getUrl('settings')),
			array('label' => Craft::t('Sections'), 'url' => UrlHelper::getUrl('settings/sections')),
			array('label' => $variables['section']->name, 'url' => UrlHelper::getUrl('settings/sections/'.$variables['section']->id)),
			array('label' => Craft::t('Entry Types'), 'url' => UrlHelper::getUrl('settings/sections/'.$variables['sectionId'].'/entrytypes')),
		);

		$variables['tabs'] = array(
			'settings'    => array('label' => Craft::t('Settings'),     'url' => '#entrytype-settings'),
			'fieldlayout' => array('label' => Craft::t('Field Layout'), 'url' => '#entrytype-fieldlayout'),
		);

		$this->renderTemplate('settings/sections/_entrytypes/edit', $variables);
	}

	/**
	 * Saves an entry type
	 */
	public function actionSaveEntryType()
	{
		$this->requirePostRequest();

		$entryType = new EntryTypeModel();

		// Set the simple stuff
		$entryType->id         = craft()->request->getPost('entryTypeId');
		$entryType->sectionId  = craft()->request->getRequiredPost('sectionId');
		$entryType->name       = craft()->request->getPost('name');
		$entryType->handle     = craft()->request->getPost('handle');
		$entryType->titleLabel = craft()->request->getPost('titleLabel');

		// Set the field layout
		$fieldLayout = craft()->fields->assembleLayoutFromPost();
		$fieldLayout->type = ElementType::Entry;
		$entryType->setFieldLayout($fieldLayout);

		// Save it
		if (craft()->sections->saveEntryType($entryType))
		{
			craft()->userSession->setNotice(Craft::t('Entry type saved.'));
			$this->redirectToPostedUrl($entryType);
		}
		else
		{
			craft()->userSession->setError(Craft::t('Couldn’t save entry type.'));
		}

		// Send the entry type back to the template
		craft()->urlManager->setRouteVariables(array(
			'entryType' => $entryType
		));
	}

	/**
	 * Reorders entry types.
	 */
	public function actionReorderEntryTypes()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$entryTypeIds = JsonHelper::decode(craft()->request->getRequiredPost('ids'));
		craft()->sections->reorderEntryTypes($entryTypeIds);

		$this->returnJson(array('success' => true));
	}

	/**
	 * Deletes an entry type.
	 */
	public function actionDeleteEntryType()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$entryTypeId = craft()->request->getRequiredPost('id');

		craft()->sections->deleteEntryTypeById($entryTypeId);
		$this->returnJson(array('success' => true));
	}
}

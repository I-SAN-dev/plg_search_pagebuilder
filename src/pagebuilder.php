<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Search.pagebuilder
 *
 * @copyright   Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/**
 * Pagebuilder search plugin.
 *
 * @since  3.2
 */
class PlgSearchPagebuilder extends JPlugin
{
	/**
	 * Determine areas searchable by this plugin.
	 *
	 * @return  array  An array of search areas.
	 *
	 * @since   1.6
	 */
	public function onContentSearchAreas()
	{
		static $areas = array(
			'sppagebuilder' => 'Seiten'
		);

		return $areas;
	}

	/**
	 * Search content (articles).
	 * The SQL must return the following fields that are used in a common display
	 * routine: href, title, section, created, text, browsernav.
	 *
	 * @param   string  $text      Target search string.
	 * @param   string  $phrase    Matching option (possible values: exact|any|all).  Default is "any".
	 * @param   string  $ordering  Ordering option (possible values: newest|oldest|popular|alpha|category).  Default is "newest".
	 * @param   mixed   $areas     An array if the search it to be restricted to areas or null to search all areas.
	 *
	 * @return  array  Search results.
	 *
	 * @since   1.6
	 */
	public function onContentSearch($text, $phrase = '', $ordering = '', $areas = null)
	{
		$db = JFactory::getDbo();
		$app = JFactory::getApplication();
		$menu = $app->getMenu();
		$user = JFactory::getUser();
		$groups = implode(',', $user->getAuthorisedViewLevels());
		$tag = JFactory::getLanguage()->getTag();

		require_once JPATH_SITE . '/components/com_content/helpers/route.php';
		require_once JPATH_ADMINISTRATOR . '/components/com_search/helpers/search.php';

		$searchText = $text;

		if (is_array($areas))
		{
			if (!array_intersect($areas, array_keys($this->onContentSearchAreas())))
			{
				return array();
			}
		}

		$limit = $this->params->def('search_limit', 50);

		$nullDate = $db->getNullDate();
		$date = JFactory::getDate();
		$now = $date->toSql();

		$text = trim($text);

		if ($text == '')
		{
			return array();
		}

		switch ($phrase)
		{
			case 'exact':
				$text = $db->quote('%' . $db->escape($text, true) . '%', false);
				$wheres2 = array();
				$wheres2[] = 'a.title LIKE ' . $text;
				$wheres2[] = 'a.text LIKE ' . $text;
				$where = '(' . implode(') OR (', $wheres2) . ')';
				break;

			case 'all':
			case 'any':
			default:
				$words = explode(' ', $text);
				$wheres = array();

				foreach ($words as $word)
				{
					$word = $db->quote('%' . $db->escape($word, true) . '%', false);
					$wheres2 = array();
					$wheres2[] = 'LOWER(a.title) LIKE LOWER(' . $word . ')';
					$wheres2[] = 'LOWER(a.text) LIKE LOWER(' . $word . ')';
					$wheres[] = implode(' OR ', $wheres2);
				}

				$where = '(' . implode(($phrase == 'all' ? ') AND (' : ') OR ('), $wheres) . ')';
				break;
		}

		switch ($ordering)
		{
			case 'oldest':
				$order = 'a.created_time ASC';
				break;

			case 'alpha':
				$order = 'a.title ASC';
				break;


			case 'newest':
			default:
				$order = 'a.created_time DESC';
				break;
		}

		$rows = array();
		$query = $db->getQuery(true);

		// Search articles.
		if ($limit > 0)
		{
			$query->clear();

			// SQLSRV changes.
			$case_when = ' CASE WHEN ';
			$case_when .= $query->charLength('a.alias', '!=', '0');
			$case_when .= ' THEN ';
			$a_id = $query->castAsChar('a.id');
			$case_when .= $query->concatenate(array($a_id, 'a.alias'), ':');
			$case_when .= ' ELSE ';
			$case_when .= $a_id . ' END as slug';

			$query->select('a.id, a.title AS title, a.text AS text, a.og_description AS metadesc, a.og_title AS metakey, a.created_time AS created, a.language')
				->select( $case_when . ',' . '\'2\' AS browsernav')

				->from('#__sppagebuilder AS a')
				->where(
					'(' . $where . ') AND a.published = 1 AND a.access IN (' . $groups . ') '
				)
				->group('a.id, a.title, a.og_description, a.og_title, a.created_time, a.text, a.alias')
				->order($order);

			// Filter by language.
			if ($app->isSite() && JLanguageMultilang::isEnabled())
			{
				$query->where('a.language in (' . $db->quote($tag) . ',' . $db->quote('*') . ')');
			}

			$db->setQuery($query, 0, $limit);
			try
			{
				$list = $db->loadObjectList();
			}
			catch (RuntimeException $e)
			{
				echo $e->getMessage();
				$list = array();
				JFactory::getApplication()->enqueueMessage(JText::_('JERROR_AN_ERROR_HAS_OCCURRED'), 'error');
			}
			$limit -= count($list);

			if (isset($list))
			{
				foreach ($list as $key => $item)
				{

					// Generate nice links! Respecting menu items!
					$link = 'index.php?option=com_sppagebuilder&view=page&id='.$item->id;

					$menuitem = $menu->getItems('link', $link, true);

					if(isset($menuitem) && isset($menuitem->id))
					{
						$link .= '&Itemid='.$menuitem->id;
					}

					$list[$key]->href = JRoute::_($link);

				}
			}
			$rows[] = $list;
		}

		$results = array();

		/*
		 * Postprocess result
		 */

		if (count($rows))
		{
			foreach ($rows as $row)
			{
				$new_row = array();

				foreach ($row as $article)
				{
					// Fetch content as array
					$textobj = json_decode($article->text);


					// Flatten deep array
					// this is some cool stuff from stackoverflow, thanks VolkerK http://stackoverflow.com/users/4833/volkerk
					$iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($textobj));
					$text = '';
					foreach($iterator as $key=>$value)
					{
						if($key == 'title' || $key == "text")
						{
							$text .= $value.' ';
						}
					}
					$article->text = $text;

					if (SearchHelper::checkNoHtml($article, $searchText, array('text', 'title', 'metadesc', 'metakey')))
					{
						$new_row[] = $article;
					}
				}

				$results = array_merge($results, (array) $new_row);
			}
		}

		return $results;
	}
}

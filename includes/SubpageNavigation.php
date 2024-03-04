<?php
/**
 * This file is part of the MediaWiki extension SubpageNavigation.
 *
 * SubpageNavigation is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * SubpageNavigation is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with SubpageNavigation.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup extensions
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2023, https://wikisphere.org
 */

use MediaWiki\Extension\SubpageNavigation\Tree as SubpageNavigationTree;
use MediaWiki\MediaWikiServices;

class SubpageNavigation {
	const MODE_DEFAULT = 1;
	const MODE_FOLDERS = 2;
	const MODE_FILESYSTEM = 3;
	const MODE_COUNT = 4;

	/**
	 * @param User|null $user
	 */
	public static function initialize( $user ) {
	}

	/**
	 * @param Skin $skin
	 * @return bool
	 */
	public static function breadcrumbIsEnabled( $skin ) {
		if ( !empty( $GLOBALS['wgSubpageNavigationDisableBreadcrumb'] ) ) {
			return false;
		}

		$skinName = $skin->getSkinName();
		// @TODO adjust as needed
		return ( $skinName !== 'vector-2022' );
	}

	/**
	 * @param Title $title
	 * @return string
	 */
	public static function getSubpageHeader( $title ) {
		// or use getPrefixedDBKey
		$limit = isset( $GLOBALS['wgSubpageNavigationArticleHeaderSubpagesThreshold'] )
		&& is_numeric( $GLOBALS['wgSubpageNavigationArticleHeaderSubpagesThreshold'] )
			? (int)$GLOBALS['wgSubpageNavigationArticleHeaderSubpagesThreshold']
			: 20;
		$limit_ = $limit + 1;
		$subpages = self::getSubpages( $title->getDBkey() . '/', $title->getNamespace(), $limit_ );

		if ( empty( $subpages ) ) {
			return false;
		}
		$threshold = ( count( $subpages ) === $limit_ );
		if ( $threshold ) {
			$subpages = array_slice( $subpages, 0, $limit );
		}
		$titlesText = array_map( static function ( $value ) {
			return $value->getText();
		}, $subpages );
		$dbr = wfGetDB( DB_REPLICA );
		$childrenCount = self::getChildrenCount( $dbr, $titlesText, $title->getNamespace() );
		$services = MediaWikiServices::getInstance();
		$linkRenderer = $services->getLinkRenderer();
		$children = Html::openElement( 'ul', [ 'class' => 'subpage-navigation-list' . ( count( $subpages ) > $limit ? ' incomplete' : '' ) ] ) . "\n";
		$children .= implode( array_map( static function ( $value ) use ( $title, $linkRenderer, &$childrenCount ) {
			$label = substr( $value->getText(), strlen( $title->getDBkey() ) + 1 );
			$childCount = array_shift( $childrenCount );
			$attr = [];
			if ( $childCount > 0 ) {
				$attr['style'] = 'font-weight:bold';
			}
			return Html::rawElement( 'li', $attr,  $linkRenderer->makeKnownLink( $value,
				$label . ( !$childCount ? '' : ' (' . $childCount . ')' ) ) );
		}, $subpages ) );

		$children .= Html::closeElement( 'ul' );
		// @see TemplatesOnThisPageFormatter -> format
		$outText = Html::openElement( 'div', [ 'class' => 'mw-subpageNavigationExplanation mw-editfooter-toggler mw-icon-arrow-expanded' ] );
		$outText .= wfMessage( 'subpagenavigation-list-explanation' )->plain();
		$outText .= Html::closeElement( 'div' );
		$outText .= $children;
		if ( $threshold ) {
			$specialPage = SpecialPage::getTitleFor( 'SubpageNavigationBrowse', $title->getDBkey() );
			$outText .= Html::rawElement( 'div', [
				'class' => 'subpagenavigation-article-header-show-more'
			],
				$linkRenderer->makeKnownLink( $specialPage, wfMessage( 'subpagenavigation-list-show-all' )->plain() )
			);
		}
		// @see EditPage
		// return Html::rawElement( 'div', [ 'class' => 'subpageHeader' ],
		// 	$templateListFormatter->format( $templates, $type )
		// );

		return Html::rawElement(
			'div',
			[
				'class' => 'subpageNavigation mw-pt-translate-navigation noprint'
			],
			$outText
		);
	}

	/**
	 * @param Title $title
	 * @return string
	 */
	public static function breadCrumbNavigation( $title ) {
		$titleText = $title->getText();
		$services = MediaWikiServices::getInstance();
		$linkRenderer = $services->getLinkRenderer();
		$separator = '&#32;/&#32;';

		$specialPages = SpecialPage::getTitleFor( 'Specialpages' );
		if ( $title->isSpecialPage() && $title->getFullText() !== $specialPages->getFullText() ) {
			$specialPageFactory = $services->getSpecialPageFactory();
			$page = $specialPageFactory->getPage( $titleText );
			// invalid special page
			if ( !$page ) {
				return false;
			}
			return $linkRenderer->makeKnownLink( $specialPages, $specialPageFactory->getPage( 'SpecialPages' )->getDescription() )
				. $separator . $page->getDescription();
		}
		$links = [];
		// phpcs:ignore Generic.ControlStructures.DisallowYodaConditions.Found
		if ( false === self::parseSubpage( $titleText, $current, $links ) ) {
			return false;
		}
		return implode( $separator, $links )
			. ( count( $links ) ? $separator : '' ) . $current;
	}

	/**
	 * @param string $titleText
	 * @param string &$current
	 * @param array &$arr
	 * @return bool|array
	 */
	public static function parseSubpage( $titleText, &$current = '', &$arr = [] ) {
		$services = MediaWikiServices::getInstance();
		$linkRenderer = $services->getLinkRenderer();

		$strStrip = strip_tags( $titleText );
		if ( strpos( $strStrip, '/' ) === false ) {
			return false;
		}

		// @see skins/skin.php -> subPageSubtitle()
		$links = explode( '/', $strStrip );
		$growinglink = '';
		$display = '';
		$current = '';
		$currentTitle = null;
		foreach ( $links as $key => $link ) {
			$growinglink .= $link;
			$display .= $link;
			$linkObj = Title::newFromText( $growinglink );

			if ( is_object( $linkObj ) && $linkObj->isKnown() ) {
				$currentTitle = $linkObj;
				$arr[] = $linkRenderer->makeKnownLink( $linkObj, $display );
				$current = $display;
				$display = '';
			} else {
				$display .= '/';
			}
			$growinglink .= '/';
		}
		if ( !count( $arr ) ) {
			return false;
		}
		$title = Title::newFromText( $strStrip );
		if ( is_object( $title ) && $title->isKnown() ) {
			array_pop( $arr );

		// handle non existing article
		} else {
			$current = substr( $title->getText(), strlen( $currentTitle->getText() ) + 1 );
		}

		if ( $strStrip !== $titleText ) {
			$current = str_replace( $strStrip, $current, $titleText );
		}

		return true;
	}

	/**
	 * @param Title $title
	 * @return Title
	 */
	public static function getFirstAncestor( $title ) {
		$links = explode( '/', $title->getFullText() );
		array_pop( $links );
		$growinglink = '';
		$ret = null;
		foreach ( $links as $key => $link ) {
			$growinglink .= $link;
			$linkObj = Title::newFromText( $growinglink );
			if ( is_object( $linkObj ) && $linkObj->isKnown() ) {
				$ret = $linkObj;
			}
			$growinglink .= '/';
		}
		return $ret;
	}

	/**
	 * @return CachedBagOStuff|LocalServerObjectCache
	 */
	public static function getCache() {
		switch ( $GLOBALS['wgSubpageNavigationCacheStore'] ) {
			case 'LocalServerObjectCache':
				return MediaWikiServices::getInstance()->getLocalServerObjectCache();

			case 'SessionCache':
			default:
				// @see MediaWiki\Session\SessionManager
				$config = MediaWikiServices::getInstance()->getMainConfig();
				$store = \ObjectCache::getInstance( $config->get( MediaWiki\MainConfigNames::SessionCacheType ) );
				return new CachedBagOStuff( $store );
		}
	}

	/**
	 * @param string $cond
	 * @return int
	 */
	public static function getTouched( $cond ) {
		$dbr = wfGetDB( DB_REPLICA );
		$pageTable = $dbr->tableName( 'page' );
		$sql = "SELECT page_touched FROM $pageTable WHERE $cond ORDER BY page_touched DESC LIMIT 1";

		$res = $dbr->query( $sql, __METHOD__ );
		$row = $res->fetchObject();
		if ( !$row ) {
			return 0;
		}

		return $row->page_touched;
	}

	/**
	 * @param string $prefix
	 * @param int $namespace
	 * @param int|null $limit
	 * @return array
	 */
	public static function getSubpages( $prefix, $namespace, $limit = null ) {
		$cache = self::getCache();
		$obj = $cache->get( 'subpage-navigation-keys' );

		if ( $obj === false ) {
			$obj = [];
		}

		$dbr = wfGetDB( DB_REPLICA );
		$cond = 'page_namespace = ' . $namespace
			 . ' AND page_is_redirect = 0'
			 . ( $prefix != '/' ? ' AND page_title LIKE ' . $dbr->addQuotes( $prefix . '%' )
				: '' );

		$touched = self::getTouched( $cond );

		$key = md5( $cond );
		$key_ = 'subpage-navigation-' . $key;
		if ( !empty( $obj[$key] ) && $obj[$key] === $touched ) {
			$ret = $cache->get( $key_ );
			// this should always be true
			if ( $ret !== false ) {
				return $ret;
			}
		}

		$dbr = wfGetDB( DB_REPLICA );
		$sql = self::subpagesSQL( $dbr, $prefix, $namespace, self::MODE_DEFAULT );
		if ( $limit ) {
			$offset = 0;
			$sql = $dbr->limitResult( $sql, $limit, $offset );
		}
		$res = $dbr->query( $sql, __METHOD__ );
		$ret = [];
		foreach ( $res as $row ) {
			$title = Title::newFromRow( $row );
			if ( $title->isKnown() ) {
				$ret[] = $title;
			}
		}

		$obj[$key] = $touched;
		$cache->set( 'subpage-navigation-keys', $obj, $cache::TTL_INDEFINITE );
		$cache->set( $key_, $ret, $cache::TTL_INDEFINITE );

		return $ret;
	}

	/**
	 * @param \IDatabase $dbr
	 * @param array $titlesText
	 * @param int $namespace
	 * @return array
	 */
	public static function getChildrenCount( $dbr, $titlesText, $namespace ) {
		$cache = self::getCache();
		$obj = $cache->get( 'subpage-navigation-children-keys' );

		if ( $obj === false ) {
			$obj = [];
		}

		$arr = [];
		foreach ( $titlesText as $text ) {
			$arr[] = 'page_title LIKE ' . $dbr->addQuotes( str_replace( ' ', '_', $text ) . '%' );
		}

		$cond = 'page_namespace = ' . $namespace
			 . ' AND page_is_redirect = 0'
			 . ( count( $arr ) ? ' AND ( ' . implode( ' OR ', $arr ) . ')' : '' );

		$touched = self::getTouched( $cond );

		$key = md5( $cond );
		$key_ = 'subpage-navigation-children' . $key;

		if ( !empty( $obj[$key] ) && $obj[$key] === $touched ) {
			$ret = $cache->get( $key_ );
			// this should always be true
			if ( $ret !== false ) {
				return $ret;
			}
		}

		// @ATTENTION!! queryMulti has been removed
		// from Wikimedia\Rdbms\Database since MW 1.4.1 !!
		if ( !method_exists( $dbr, 'queryMulti' ) ) {
			// @credits: Zoranzoki21 aka Kizule
			$sqls = array_map( function ( $text ) use ( $dbr, $namespace ) {
				return self::subpagesSQL( $dbr, str_replace( ' ', '_', $text ) . '/', $namespace, self::MODE_COUNT );
			}, $titlesText );

			$ret = [];
			foreach ( $sqls as $sql ) {
				$res = $dbr->query( $sql, __METHOD__ );
				$row = $res->fetchObject();
				if ( $row ) {
					$ret[] = $row->count;
				}
			}
		// ----------------------
		} else {
			$sqls = [];
			foreach ( $titlesText as $text ) {
				$text = str_replace( ' ', '_', $text );
				$sqls[] = self::subpagesSQL( $dbr, "{$text}/", $namespace, self::MODE_COUNT );
			}

			$resMap = $dbr->queryMulti( $sqls, __METHOD__ );
			// @see DatabaseMysqlTest
			reset( $resMap );
			$ret = [];
			foreach ( $resMap as $i => $qs ) {
				if ( is_iterable( $qs->res ) ) {
					foreach ( $qs->res as $row ) {
						$ret[] = $row->count;
						break;
					}
				}
			}
		}

		$obj[$key] = $touched;
		$cache->set( 'subpage-navigation-children-keys', $obj, $cache::TTL_INDEFINITE );
		$cache->set( $key_, $ret, $cache::TTL_INDEFINITE );

		return $ret;
	}

	/**
	 * @param OutputPage $outputPage
	 * @param array $items
	 */
	public static function addHeaditem( $outputPage, $items ) {
		foreach ( $items as $key => $val ) {
			[ $type, $url ] = $val;
			switch ( $type ) {
				case 'stylesheet':
					$item = '<link rel="stylesheet" href="' . $url . '" />';
					break;
				case 'script':
					$item = '<script src="' . $url . '"></script>';
					break;
			}
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$outputPage->addHeadItem( 'bookletnavigator_head_item' . $key, $item );
		}
	}

	/**
	 * @param \IDatabase $dbr
	 * @param string $prefix
	 * @param int $namespace
	 * @param int $mode
	 * @return string|\ResultWrapper
	 */
	public static function subpagesSQL( $dbr, $prefix, $namespace, $mode ) {
		$cond = 'page_namespace = ' . $namespace
			 . ' AND page_is_redirect = 0'
			 . ( $prefix != '/' ? ' AND page_title LIKE ' . $dbr->addQuotes( $prefix . '%' )
				: '' );

		$pageTable = $dbr->tableName( 'page' );

		// @FIXME use the new MediaWiko's SQL api if possible

		switch ( $mode ) {
			case self::MODE_COUNT:
			case self::MODE_DEFAULT:
				$select = ( $mode !== self::MODE_COUNT ? ' DISTINCT t1.*' : 'COUNT(*) as count' );

				// the 2nd join is used to select
				// intermediate pages and to exclude them
				return "SELECT $select
FROM (
		SELECT page_id, page_title, page_namespace
		FROM $pageTable
		WHERE $cond
	) AS t1
LEFT JOIN(
    SELECT page_title
    FROM $pageTable
	WHERE $cond
) AS t2
ON t1.page_title LIKE CONCAT(t2.page_title, '/%')
WHERE ( t2.page_title IS NULL OR t1.page_title = t2.page_title )
";

			// the 3rd join is used to select
			// only t1 entries with children
			case self::MODE_FOLDERS:
				return "SELECT DISTINCT t1.*
FROM (
		SELECT page_id, page_title, page_namespace
		FROM $pageTable
		WHERE $cond
	) AS t1
LEFT JOIN(
    SELECT page_title
    FROM $pageTable
	WHERE $cond
) AS t2
ON t1.page_title LIKE CONCAT(t2.page_title, '/%')
JOIN(
    SELECT page_title
    FROM $pageTable
	WHERE $cond
) AS t3
ON t3.page_title LIKE CONCAT(t1.page_title, '/%')
WHERE ( t2.page_title IS NULL OR t1.page_title = t2.page_title )
";

			// the first select selects only
			// articles with children (excluding intermediate
			// pages), and the 2nd select selects
			// only articles without children

			case self::MODE_FILESYSTEM:
				return "SELECT DISTINCT t1.*
FROM (
		SELECT page_id, page_title, page_namespace
		FROM $pageTable
		WHERE $cond
	) AS t1
LEFT JOIN(
    SELECT page_title
    FROM $pageTable
	WHERE $cond
) AS t2
ON t1.page_title LIKE CONCAT(t2.page_title, '/%')
JOIN(
    SELECT page_title
    FROM $pageTable
	WHERE $cond
) AS t3
ON t3.page_title LIKE CONCAT(t1.page_title, '/%')
WHERE ( t2.page_title IS NULL OR t1.page_title = t2.page_title )
UNION
SELECT DISTINCT t1.*
FROM (
		SELECT page_id, page_title, page_namespace
		FROM $pageTable
		WHERE $cond
	) AS t1
LEFT JOIN(
    SELECT page_title
    FROM $pageTable
	WHERE $cond
) AS t2
ON t1.page_title LIKE CONCAT(t2.page_title, '/%')
LEFT JOIN(
    SELECT page_title
    FROM $pageTable
	WHERE $cond
) AS t3
ON t3.page_title LIKE CONCAT(t1.page_title, '/%')
WHERE ( t2.page_title IS NULL OR t1.page_title = t2.page_title )
";

		} // switch
	}

	/**
	 * @param Output $output
	 * @return string
	 */
	public static function getTreeHtml( $output ) {
		$options = [];
		$tree = new SubpageNavigationTree( $options );
		$treeHtml = $tree->getTree( $output );

		// this creates a MW's TOC like toggle
		return '<div id="subpagenavigation-tree" class="SubpageNavigationTreeContainer">'
			. SubpageNavigationTree::tocList( $treeHtml ) . '</div>';
	}

}

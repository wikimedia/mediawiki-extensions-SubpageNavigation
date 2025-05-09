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
 * @copyright Copyright ©2023-2025, https://wikisphere.org
 */

use MediaWiki\Extension\SubpageNavigation\Tree as SubpageNavigationTree;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

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
		if ( empty( $GLOBALS['wgSubpageNavigationShowBreadcrumbs'] ) ) {
			return false;
		}

		$skinName = $skin->getSkinName();
		// @TODO adjust as needed
		return ( $skinName !== 'vector-2022' );
	}

	/**
	 * @param string $varName
	 * @param int $limit
	 * @return int
	 */
	public static function getSetGlobalLimit( $varName, $limit ) {
		if ( isset( $GLOBALS["wg$varName"] )
			&& is_numeric( $GLOBALS["wg$varName"] )
		) {
			$GLOBALS["wg$varName"] = (int)$GLOBALS["wg$varName"];
			return (int)$GLOBALS["wg$varName"];
		}
		$GLOBALS["wg$varName"] = $limit;
		return $limit;
	}

	/**
	 * @param Title $title
	 * @return string
	 */
	public static function getSubpageHeader( $title ) {
		$limit = self::getSetGlobalLimit( 'SubpageNavigationArticleHeaderSubpagesLimit', 20 );
		$limit_ = $limit + 1;

		// set $countLimit as SubpageNavigationArticleHeaderSubpagesLimit if not set
		$countLimit = self::getSetGlobalLimit( 'SubpageNavigationCountSubpagesLimit', -1 );
		if ( $countLimit === -1 ) {
			$countLimit = $limit;
		}

		// disable display subpages for specific paths
		// this is a work-around in case there are too many subpages
		// a proper solution is a pre-computation of each parent page
		// involved in an edit
		if ( is_array( $GLOBALS['wgSubpageNavigationDisablePaths'] ) ) {
			foreach ( $GLOBALS['wgSubpageNavigationDisablePaths'] as $path ) {
				if ( strpos( $title->getFullText(), $path ) === 0 ) {
					return false;
				}
			}
		}

		// or use getPrefixedDBKey
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

		$services = MediaWikiServices::getInstance();
		$dbr = self::getDB( DB_REPLICA );
		$childrenCount = self::getChildrenCount( $dbr, $titlesText, $title->getNamespace(), $countLimit );
		$linkRenderer = $services->getLinkRenderer();

		$children = Html::openElement( 'ul', [ 'class' => [
			'subpage-navigation-list',
			'incomplete' => count( $subpages ) > $limit,
		] ] ) . "\n";

		$children .= implode( array_map( static function ( $value ) use ( $title, $linkRenderer, &$childrenCount, $countLimit ) {
			$label = substr( $value->getText(), strlen( $title->getDBkey() ) + 1 );
			$childCount = array_shift( $childrenCount );
			$attr = [];
			if ( $childCount > 0 ) {
				$attr['style'] = 'font-weight:bold';
			}
			return Html::rawElement( 'li', $attr, $linkRenderer->makeKnownLink( $value,
				$label . ( !$childCount ? '' : ' (' . self::formatChildCount( $childCount, $countLimit ) . ')' ) ) );
		}, $subpages ) );

		$children .= Html::closeElement( 'ul' );
		// @see TemplatesOnThisPageFormatter -> format
		$outText = Html::element( 'div', [ 'class' => [
			'mw-subpageNavigationExplanation',
			'mw-editfooter-toggler',
			'mw-icon-arrow-expanded',
		] ], wfMessage( 'subpagenavigation-list-explanation' )->plain() );
		$outText .= $children;
		if ( $threshold ) {
			$specialPage = SpecialPage::getTitleFor( 'SubpageNavigationBrowse', $title->getDBkey() );
			$outText .= Html::rawElement( 'div', [
				'class' => 'subpagenavigation-article-header-show-more'
			],
				$linkRenderer->makeKnownLink( $specialPage, wfMessage( 'subpagenavigation-list-show-all' )->plain(),
					[], [ 'namespace' => $title->getNamespace() ] )
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
	 * @return BagOStuff|false
	 */
	public static function getCache() {
		if ( !empty( $GLOBALS['wgSubpageNavigationDisableCache'] ) ) {
			return false;
		}

		switch ( $GLOBALS['wgSubpageNavigationCacheStore'] ) {
			case 'LocalServerObjectCache':
				return MediaWikiServices::getInstance()->getLocalServerObjectCache();

			case 'SessionCache':
			default:
				// @see MediaWiki\Session\SessionManager
				$config = MediaWikiServices::getInstance()->getMainConfig();
				$store = \ObjectCache::getInstance( $config->get(
					class_exists( 'MainConfigNames' ) ? MainConfigNames::SessionCacheType : 'SessionCacheType' ) );
				return new CachedBagOStuff( $store );
		}
	}

	/**
	 * @param string $cond
	 * @return int
	 */
	public static function getTouched( $cond ) {
		$dbr = self::getDB( DB_REPLICA );
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
		$callback = function () use ( $prefix, $namespace, $limit ) {
			$dbr = self::getDB( DB_REPLICA );
			$sql = self::subpagesSQL( $dbr, $prefix, $namespace, self::MODE_DEFAULT );
			if ( $limit ) {
				$offset = 0;
				$sql = $dbr->limitResult( $sql, $limit, $offset );
			}
			// phpcs:ignore MediaWiki.Usage.MagicConstantClosure.FoundConstantMethod
			$res = $dbr->query( $sql, __METHOD__ );
			$ret = [];
			foreach ( $res as $row ) {
				$title = Title::newFromRow( $row );
				if ( $title->isKnown() ) {
					$ret[] = $title;
				}
			}
			return $ret;
		};
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
		if ( ( $cache = self::getCache() ) === false ) {
			return $callback();
		}

		$obj = $cache->get( 'subpage-navigation-keys' );

		if ( $obj === false ) {
			$obj = [];
		}

		$dbr = self::getDB( DB_REPLICA );
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

		$ret = $callback();
		$obj[$key] = $touched;
		$cache->set( 'subpage-navigation-keys', $obj, $cache::TTL_INDEFINITE );
		$cache->set( $key_, $ret, $cache::TTL_INDEFINITE );

		return $ret;
	}

	/**
	 * @param int $count
	 * @param int $countLimit
	 * @return string
	 */
	public static function formatChildCount( $count, $countLimit ) {
		if ( $count <= $countLimit ) {
			return $count;
		}
		return $countLimit . '+';
	}

	/**
	 * @param IDatabase $dbr
	 * @param array $titlesText
	 * @param int $namespace
	 * @param int $countLimit
	 * @return array
	 */
	public static function getChildrenCount( $dbr, $titlesText, $namespace, $countLimit ) {
		$callback = function () use ( $dbr, $titlesText, $namespace, $countLimit ) {
			// @ATTENTION!! queryMulti has been removed
			// from Wikimedia\Rdbms\Database since MW 1.4.1 !!
			if ( !method_exists( $dbr, 'queryMulti' ) ) {
				// @credits: Zoranzoki21 aka Kizule
				$sqls = array_map( function ( $text ) use ( $dbr, $namespace, $countLimit ) {
					return self::subpagesSQL( $dbr, str_replace( ' ', '_', $text ) . '/', $namespace, self::MODE_COUNT, $countLimit );
				}, $titlesText );

				$ret = [];
				foreach ( $sqls as $sql ) {
					// phpcs:ignore MediaWiki.Usage.MagicConstantClosure.FoundConstantMethod
					$res = $dbr->query( $sql, __METHOD__ );
					$row = $res->fetchObject();
					if ( $row ) {
						$ret[] = $row->count;
					}
				}
			// ----------------------
				return $ret;
			}
			$sqls = [];
			foreach ( $titlesText as $text ) {
				$text = str_replace( ' ', '_', $text );
				$sqls[] = self::subpagesSQL( $dbr, "{$text}/", $namespace, self::MODE_COUNT, $countLimit );
			}

			// phpcs:ignore MediaWiki.Usage.MagicConstantClosure.FoundConstantMethod
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
			return $ret;
		};

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
		if ( ( $cache = self::getCache() ) === false ) {
			return $callback();
		}

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

		$ret = $callback();
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
	 * @param IDatabase $dbr
	 * @param string $prefix
	 * @param int $namespace
	 * @param int $mode
	 * @param int $countLimit
	 * @return string
	 */
	public static function subpagesSQL( $dbr, $prefix, $namespace, $mode, $countLimit = 0 ) {
		// @TODO use the new MediaWiki's SQL api
		$cond = 'page_namespace = ' . $namespace
			 . ' AND page_is_redirect = 0'
			 . ( $prefix != '/' ? ' AND page_title LIKE ' . $dbr->addQuotes( $prefix . '%' )
				: '' );

		$sqlConcat = static function ( $str1, $str2 ) use ( $dbr ) {
			switch ( $dbr->getType() ) {
				case 'sqlite':
					return "($str1 || $str2)";
			}
			return "CONCAT($str1, $str2)";
		};

		$tableName = $dbr->tableName( 'page' );

		// the NOT EXIST condition
		// excludes all child pages
		// except the page itself
		$directChildren = "SELECT DISTINCT t1.*
FROM $tableName AS t1
WHERE $cond AND NOT EXISTS (
	SELECT 1
	FROM $tableName AS t2
	WHERE $cond AND
	t1.page_title LIKE " . $sqlConcat( "t2.page_title", "'/%'" ) . "
)";

		switch ( $mode ) {
			case self::MODE_COUNT:
			// limit to countLimit, since the computation
			// cost is a cartesian product
				return "SELECT COUNT(*) as count
FROM ( $directChildren LIMIT " . ( $countLimit + 1 ) . " ) as limit_count";
			case self::MODE_DEFAULT:
				// or use CONVERT(t1.page_title USING utf8mb3) COLLATE utf8_general_ci
				return "$directChildren ORDER BY t1.page_title ASC";

			case self::MODE_FOLDERS:
			case self::MODE_FILESYSTEM:
// the inner join selects only articles
// with children
				$onlyFolders = "SELECT DISTINCT t1.*
FROM $tableName AS t1
	JOIN(
		SELECT page_title as page_title_
		FROM $tableName
		WHERE $cond
	) AS t2
ON t2.page_title_ LIKE " . $sqlConcat( "t1.page_title", "'/%'" ) . "
WHERE $cond AND NOT EXISTS (
	SELECT 1
	FROM $tableName AS t2
	WHERE $cond AND
	t1.page_title LIKE " . $sqlConcat( "t2.page_title", "'/%'" ) . "
) ORDER BY t1.page_title ASC";

				if ( $mode === self::MODE_FOLDERS ) {
					return $onlyFolders;
				}

				// theoretical max limit, required to order
				// with UNION
				$maxLimit = 65535;

				return "( $onlyFolders limit $maxLimit )
					UNION ( $directChildren ORDER BY t1.page_title ASC limit $maxLimit )";
		}
	}

	/**
	 * @param OutputPage $output
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

	/**
	 * @param int $db
	 * @return \Wikimedia\Rdbms\DBConnRef
	 */
	public static function getDB( $db ) {
		if ( !method_exists( MediaWikiServices::class, 'getConnectionProvider' ) ) {
			// @see https://gerrit.wikimedia.org/r/c/mediawiki/extensions/PageEncryption/+/1038754/comment/4ccfc553_58a41db8/
			return MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( $db );
		}
		$connectionProvider = MediaWikiServices::getInstance()->getConnectionProvider();
		switch ( $db ) {
			case DB_PRIMARY:
				return $connectionProvider->getPrimaryDatabase();
			case DB_REPLICA:
			default:
				return $connectionProvider->getReplicaDatabase();
		}
	}

	/**
	 * @return MediaWiki\Html\Html|Html
	 */
	// phpcs:ignore MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	public static function HtmlClass() {
		if ( class_exists( 'MediaWiki\Html\Html' ) ) {
			return MediaWiki\Html\Html::class;
		}
		return Html::class;
	}
}

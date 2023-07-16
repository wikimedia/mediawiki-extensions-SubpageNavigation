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

use MediaWiki\MediaWikiServices;

class SubpageNavigation {
	
	/**
	 * @param User|null $user
	 */
	public static function initialize( $user ) { }
	
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
		$subpages = self::getSubpages( $title->getDBkey() . '/' );
		
		if ( empty( $subpages ) ) {
			return false;
		}
		
		$limit = 100;
		$services = MediaWikiServices::getInstance();
		$linkRenderer = $services->getLinkRenderer();
		
		$children = Html::openElement( 'ul', [ 'class' => 'subpage-navigation-list' . ( count( $subpages ) > $limit ? ' incomplete' : '' ) ] ) . "\n";

		$children .= implode( array_map( static function ( $value ) use ( $title, $linkRenderer ) {
			return Html::rawElement( 'li', [],  $linkRenderer->makeKnownLink( $value,
				substr( $value->getText(), strlen( $title->getDBkey() ) + 1 ) ) );
		}, $subpages ) );
		
		if ( count( $subpages ) > $limit ) {
			$specialPage = SpecialPage::getTitleFor( 'subpagenavigationsubpages', $title->getDBkey() );
			$children .= Html::rawElement( 'li', [],
				$linkRenderer->makeKnownLink( $specialPage, wfMessage( 'subpagenavigation-list-show-all' )->plain() )
			);
		}
	
		$children .= Html::closeElement( 'ul' );

		// @see TemplatesOnThisPageFormatter -> format		
		$outText = Html::openElement( 'div', [ 'class' => 'mw-subpageNavigationExplanation mw-editfooter-toggler mw-icon-arrow-expanded' ] );
		$outText .= wfMessage( 'subpagenavigation-list-explanation' )->plain();
		$outText .= Html::closeElement( 'div' );
		$outText .= $children;		
		
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
		
		$specialPages = SpecialPage::getTitleFor( 'SpecialPages' );
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

		if ( false === self::parseSubpage( $titleText, $current, $links ) ) {
			return false;
		}

		return implode( $separator, $links ) . $separator . $current;
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
	 * @param string $prefix
	 * @param int|null $limit
	 * @return array
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
	 * @param string $prefix
	 * @param int|null $limit
	 * @return array
	 */
	public static function getSubpages( $prefix, $limit = null ) {
		$dbr = wfGetDB( DB_MASTER );
		$sql = self::subpagesSQL( $dbr, $prefix );

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
		return $ret;
	}

	/**
	 * @param OutputPage $outputPage
	 * @param array $items
	 */
	public static function addHeaditem( $outputPage, $items ) {
		foreach ( $items as $key => $val ) {
			list( $type, $url ) = $val;
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
	 * @param int|null $namespace
	 * @return string
	 */
	public static function subpagesSQL( $dbr, $prefix, $namespace = NS_MAIN ) {
		$cond = 'page_namespace = ' . $namespace . ( $prefix != '/' ?
			' AND page_title LIKE ' . $dbr->addQuotes( $prefix . '%' ) : '');

		// @TODO to count direct children correctly
		// use the following https://dev.mysql.com/doc/refman/8.0/en/with.html#common-table-expressions-recursive-examples
		// (Hierarchical Data Traversal)
		return 'SELECT t.*, ( 1 - (t.page_title REGEXP \'^[0-9]+$\') ) AS isNumeric,
		(SELECT COUNT(*) FROM page WHERE ' . $cond . ' AND page_title LIKE CONCAT(t.page_title, "/%")) AS childCount
FROM (SELECT page_id, page_namespace, page_title,
	SUBSTR( page_title, 1, LOCATE(\'/\', SUBSTR( page_title, ' . ( strlen( $prefix ) + 1 ) . ') ) + ' . ( strlen( $prefix ) - 1 ) . ')
	AS subpage FROM page
WHERE ' . $cond . '
) as t
WHERE NOT EXISTS (
	SELECT 1 FROM page
	WHERE ' . $cond . '
	AND page_title = t.subpage
)
ORDER BY isNumeric, length(t.page_title), t.page_title
';
	}

}


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
 * @copyright Copyright ©2023-2024, https://wikisphere.org
 */

use MediaWiki\Extension\SubpageNavigation\Tree as SubpageNavigationTree;
use MediaWiki\MediaWikiServices;

class SubpageNavigationHooks {

	/**
	 * @param MediaWikiServices $services
	 * @return void
	 */
	public static function onMediaWikiServices( $services ) {
	}

	public static function onRegistration() {
		// $GLOBALS['wgwgNamespacesWithSubpages'][NS_MAIN] = false;
	}

	/**
	 * @param Title &$title
	 * @param null $unused
	 * @param OutputPage $output
	 * @param User $user
	 * @param WebRequest $request
	 * @param MediaWiki|MediaWiki\Actions\ActionEntryPoint $mediaWiki
	 * @return void
	 */
	public static function onBeforeInitialize( \Title &$title, $unused, \OutputPage $output, \User $user, \WebRequest $request, $mediaWiki ) {
		\SubpageNavigation::initialize( $user );
	}

	/**
	 * @see https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/extensions/Translate/+/394f20034b62f5b1ddfb7ac7d31c5d7ff3e3b253/src/PageTranslation/Hooks.php
	 * @param Article &$article
	 * @param bool|ParserOutput|null &$outputDone
	 * @param bool &$pcache
	 * @return void
	 */
	public static function onArticleViewHeader( Article &$article, &$outputDone, bool &$pcache ) {
		// *** this is used by the Translate extension
		// *** to display the "translate" link,
		// *** we use onBeforePageDisplay OutputPage -> prependHTML instead
	}

	/**
	 * @param string &$subpages
	 * @param Skin $skin
	 * @param OutputPage $out
	 * @return void|bool
	 */
	public static function onSkinSubPageSubtitle( &$subpages, $skin, $out ) {
		if ( \SubpageNavigation::breadcrumbIsEnabled( $skin ) ) {
			return false;
		}
	}

	/**
	 * @param Skin $skin
	 * @param array &$sidebar
	 */
	public static function onSkinBuildSidebar( $skin, &$sidebar ) {
		if ( empty( $GLOBALS['wgSubpageNavigationShowTree'] ) ) {
			return;
		}

		$title = $skin->getTitle();

		if ( $title->isSpecialPage() ) {
			return;
		}

		// *** place on top
		$sidebar = array_merge(
			[ 'subpagenavigation-portlet' => [] ],
			$sidebar
		);
	}

	/**
	 * @param Skin $skin
	 * @param string $portlet
	 * @param string &$html
	 */
	public static function onSkinAfterPortlet( $skin, $portlet, &$html ) {
		if ( $portlet === 'subpagenavigation-portlet' ) {
			$html = \SubpageNavigation::getTreeHtml( $skin->getOutput() );
		}
	}

	/**
	 * @param SkinTemplate $skinTemplate
	 * @param array &$links
	 * @param string &$html
	 */
	// phpcs:ignore MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	// public static function onSkinTemplateNavigation_Universal( SkinTemplate $skinTemplate, array &$links ) {
	// }

	/**
	 * @param OutputPage $outputPage
	 * @param Skin $skin
	 * @return void
	 */
	public static function onBeforePageDisplay( OutputPage $outputPage, Skin $skin ) {
		global $wgResourceBasePath;

		$title = $outputPage->getTitle();

		// with vector-2022 skin unfortunately
		// there is no way to place indicators on top
		// @see SkinVector -> isLanguagesInContentAt and ContentHeader.mustache
		if ( \SubpageNavigation::breadcrumbIsEnabled( $skin ) ) {
			$breadCrumb = \SubpageNavigation::breadCrumbNavigation( $title );
			if ( $breadCrumb !== false ) {
				$outputPage->setIndicators( [
					// *** id = mw-indicator-subpage-navigation
					'subpage-navigation' => $breadCrumb
				] );
			}
		}

		// used by WikidataPageBanner to place the banner
		// $outputPage->addSubtitle( 'addSubtitle' );

		\SubpageNavigation::addHeaditem( $outputPage, [
			[ 'stylesheet', $wgResourceBasePath . '/extensions/SubpageNavigation/resources/style.css' ],
		] );

		if ( $title->isSpecialPage() ) {
			return;
		}

		if ( !empty( $GLOBALS['wgSubpageNavigationShowTree'] ) ) {
			SubpageNavigationTree::setHeaders( $outputPage );
		}

		if ( !empty( $_REQUEST['action'] ) && $_REQUEST['action'] !== 'view' ) {
			return;
		}

		$outputPage->addModules( [ 'ext.SubpageNavigationSubpages' ] );

		// *** this is rendered after than onArticleViewHeader
		$outputPage->prependHTML( \SubpageNavigation::getSubpageHeader( $title ) );

		if ( \SubpageNavigation::breadcrumbIsEnabled( $skin ) ) {
			$titleText = $outputPage->getPageTitle();

			if ( \SubpageNavigation::parseSubpage( $titleText, $current ) ) {
				$outputPage->setPageTitle( $current );
			}
		}
	}

	/**
	 * @param Skin $skin
	 * @param array &$sidebar
	 * @return void
	 */
	public static function onSidebarBeforeOutput( $skin, &$sidebar ) {
		if ( !empty( $GLOBALS['wgSubpageNavigationDisableSidebarLink'] ) ) {
			return;
		}

		$specialpage_title = SpecialPage::getTitleFor( 'SubpageNavigationBrowse' );
		$sidebar['TOOLBOX'][] = [
			'text'   => wfMessage( 'subpagenavigation-sidebar' )->text(),
			'href'   => $specialpage_title->getLocalURL()
		];

		$sidebar['subpagenavigation-tree'][] = [
			'text'   => wfMessage( 'subpagenavigation-sidebar' )->text(),
			'href'   => $specialpage_title->getLocalURL()
		];
	}

}

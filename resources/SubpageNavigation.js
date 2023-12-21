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
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2023, https://wikisphere.org
 */

// @credits resources/src/mediawiki.action/mediawiki.action.edit.collapsibleFooter.js
( function () {
	var collapsibleLists, handleOne;

	// Collapsible lists of categories and templates
	// If changing or removing a storeKey, ensure there is a strategy for old keys.
	// E.g. detect existence via requestIdleCallback and remove. (T121646)
	collapsibleLists = [
		{
			listSel: '.subpageNavigation ul',
			togglerSel: '.mw-subpageNavigationExplanation',
			storeKey: 'mwedit-state-subpageNavigation'
		}
	];

	handleOne = function ( $list, $toggler, storeKey ) {
		var collapsedVal = '0',
			expandedVal = '1',
			// Default to collapsed if not set
			isCollapsed = mw.storage.get( storeKey ) !== expandedVal;

		// Style the toggler with an arrow icon and add a tabIndex and a role for accessibility
		$toggler.addClass( 'mw-editfooter-toggler' ).prop( 'tabIndex', 0 ).attr( 'role', 'button' );
		$list.addClass( 'mw-editfooter-list' );

		$list.makeCollapsible( {
			$customTogglers: $toggler,
			linksPassthru: true,
			plainMode: true,
			collapsed: isCollapsed
		} );

		$toggler.addClass( isCollapsed ? 'mw-icon-arrow-collapsed' : 'mw-icon-arrow-expanded' );

		$list.on( 'beforeExpand.mw-collapsible', function () {
			$toggler.removeClass( 'mw-icon-arrow-collapsed' ).addClass( 'mw-icon-arrow-expanded' );
			mw.storage.set( storeKey, expandedVal );
		} );

		$list.on( 'beforeCollapse.mw-collapsible', function () {
			$toggler.removeClass( 'mw-icon-arrow-expanded' ).addClass( 'mw-icon-arrow-collapsed' );
			mw.storage.set( storeKey, collapsedVal );
		} );
	};

	mw.hook( 'wikipage.content' ).add( function ( $contentText ) {
		var i;
		for ( i = 0; i < collapsibleLists.length; i++ ) {
			// Pass to a function for iteration-local variables
			handleOne(
				$contentText.find( collapsibleLists[ i ].listSel ),
				$contentText.find( collapsibleLists[ i ].togglerSel ),
				collapsibleLists[ i ].storeKey
			);
		}
	} );
}() );

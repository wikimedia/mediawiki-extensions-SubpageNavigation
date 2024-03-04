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

// @credits: https://www.mediawiki.org/wiki/Extension:CategoryTree

( function () {
	var loadChildren,
		config = require( './data.json' );
		// config = {defaultCtOptions: {} };

	/**
	 * @param {jQuery} $link
	 */
	function expandNode( $link ) {
		// Show the children node
		var $children = $link.parents( '.SubpageNavigationTreeItem' )
			.siblings( '.SubpageNavigationTreeChildren' )
			.css( 'display', '' );

		$link.attr( {
			title: mw.msg( 'subpagenavigation-tree-collapse' ),
			'data-subpagenavigation-state': 'expanded'
		} );

		if ( !$link.data( 'subpagenavigation-loaded' ) ) {
			loadChildren( $link, $children );
		}
	}

	/**
	 * @param {jQuery} $link
	 */
	function collapseNode( $link ) {
		// Hide the children node
		$link.parents( '.SubpageNavigationTreeItem' )
			.siblings( '.SubpageNavigationTreeChildren' )
			.css( 'display', 'none' );

		$link.attr( {
			title: mw.msg( 'subpagenavigation-tree-expand' ),
			'data-subpagenavigation-state': 'collapsed'
		} );
	}

	/**
	 * @this {Element} SubpageNavigationTreeToggle
	 */
	function handleNode() {
		var $link = $( this );
		if ( $link.attr( 'data-subpagenavigation-state' ) === 'collapsed' ) {
			expandNode( $link );
		} else {
			collapseNode( $link );
		}
	}

	/**
	 * @param {jQuery} $content
	 */
	function attachHandler( $content ) {
		$content.find( '.SubpageNavigationTreeToggle' )
			.on( 'click', handleNode )
			.attr( 'title', function () {
				return mw.msg(
					$( this ).attr( 'data-subpagenavigation-state' ) === 'collapsed' ?
						'csubpagenavigation-tree-expand' :
						'subpagenavigation-tree-collapse'
				);
			} )
			.addClass( 'SubpageNavigationTreeToggleHandlerAttached' );
	}

	/**
	 * @param {jQuery} $link
	 * @param {jQuery} $children
	 */
	loadChildren = function ( $link, $children ) {
		var $linkParentCTTag, ctTitle, ctOptions;

		/**
		 * Error callback
		 */
		function error() {
			var $retryLink;

			$retryLink = $( '<a>' )
				.text( mw.msg( 'subpagenavigation-tree-retry' ) )
				.attr( {
					role: 'button',
					tabindex: 0
				} )
				.on( 'click keypress', function ( e ) {
					if (
						e.type === 'click' ||
						e.type === 'keypress' && e.which === 13
					) {
						loadChildren( $link, $children );
					}
				} );

			$children
				.text( mw.msg( 'subpagenavigation-tree-error' ) + ' ' )
				.append( $retryLink );
		}

		$link.data( 'subpagenavigation-loaded', true );

		$children.empty().append(
			$( '<i>' )
				.addClass( 'SubpageNavigationTreeNotice' )
				.text( mw.msg( 'subpagenavigation-tree-loading' ) )
		);

		$linkParentCTTag = $link.parents( '.SubpageNavigationTreeTag' );
		ctTitle = $link.attr( 'data-subpagenavigation-title' );
		ctOptions = $linkParentCTTag.attr( 'data-subpagenavigation-options' ) || config.defaultCtOptions;

		if ( !ctTitle ) {
			error();
			return;
		}

		new mw.Api().get( {
			action: 'subpagenavigation-tree',
			title: ctTitle,
			options: ctOptions,
			uselang: mw.config.get( 'wgUserLanguage' ),
			formatversion: 2
		} ).done( function ( data ) {
			var $data;
			data = data[ 'subpagenavigation-tree' ].html;

			if ( data === '' ) {
				$data = $( '<i>' ).addClass( 'SubpageNavigationTreeNotice' )
					// or 'subpagenavigation-tree-nothing-found'
					.text( mw.msg( 'subpagenavigation-tree-no-subpages' ) );
			} else {
				$data = $( $.parseHTML( data ) );
				attachHandler( $data );
			}

			$children.empty().append( $data );
		} ).fail( error );
	};

	$( function () {
		// eslint-disable-next-line no-jquery/no-global-selector
		attachHandler( $( '#subpagenavigation-tree' ) );
	} );

	// @see mediawiki.toc/toc.js
	function initToc( tocNode ) {
		var hidden = false,
			toggleNode = tocNode.querySelector( '.toctogglecheckbox' );

		if ( !toggleNode ) {
			return;
		}

		toggleNode.addEventListener( 'change', function () {
			hidden = !hidden;
			mw.cookie.set( 'subpagenavigation-hidetoc', hidden ? '1' : null );
		} );

		if ( mw.cookie.get( 'subpagenavigation-hidetoc' ) === '1' ) {
			toggleNode.checked = true;
			hidden = true;
		}
	}

	initToc( $( '#subpagenavigation-tree' ).get( 0 ) );

}() );

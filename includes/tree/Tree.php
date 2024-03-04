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

// @credits: https://www.mediawiki.org/wiki/Extension:CategoryTree

namespace MediaWiki\Extension\SubpageNavigation;

use Exception;
use FormatJson;
use Html;
use IContextSource;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use OutputPage;
use RequestContext;
use SpecialPage;
use Title;

class Tree {

	/** @var array */
	public $mOptions = [];

	/** @var Output */
	public $output;

	/** @var LinkRenderer */
	private $linkRenderer;

	/**
	 * @return array
	 */
	public static function getDataForJs() {
		$tree = new Tree( $GLOBALS['wgSubpageNavigationDefaultOptions'] );
		return [
			'defaultCtOptions' => $tree->getOptionsAsJsStructure(),
		];
	}

	/**
	 * @param array $options
	 */
	public function __construct( array $options ) {
		$this->linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		$this->mOptions = array_merge( $options, [
			'showcount' => true,
			// 'namespace' => RequestContext::getMain()->getTitle()->getNamespace()
		] );
	}

	/**
	 * @param string $name
	 * @return mixed
	 */
	public function getOption( $name ) {
		return $this->mOptions[$name];
	}

	/**
	 * @return string
	 */
	public function getOptionsAsCacheKey() {
		$key = '';

		foreach ( $this->mOptions as $k => $v ) {
			if ( is_array( $v ) ) {
				$v = implode( '|', $v );
			}
			$key .= $k . ':' . $v . ';';
		}

		return $key;
	}

	/**
	 * @return mixed
	 */
	public function getOptionsAsJsStructure() {
		$opt = $this->mOptions;
		// $opt[ 'namespace' ] = RequestContext::getMain()->getTitle()->getNamespace();
		return self::encodeOptions( $opt, 'json' );
	}

	/**
	 * @see MediaWiki\Linker -> tocList
	 * @param string $toc
	 * @param Language|null $lang
	 * @return mixed
	 */
	public static function tocList( $toc, Language $lang = null ) {
		$lang ??= RequestContext::getMain()->getLanguage();

		// wfMessage( 'toc' )->inLanguage( $lang )->escaped();
		$title = wfMessage( 'subpagenavigation-toc-title' )->inLanguage( $lang )->escaped();

		return '<div id="subpagenavigation-toc" style="margin:auto" class="toc" role="navigation" aria-labelledby="subpagenavigation-mw-toc-heading">'
			// . Html::element( 'input', [
			//	'type' => 'checkbox',
			//	'role' => 'button',
			//	'id' => 'toctogglecheckbox',
			//	'class' => 'toctogglecheckbox',
			//	'style' => 'display:none',
			//] )
			. '<input type="checkbox" role="button" id="subpagenavigation-toctogglecheckbox"
				class="toctogglecheckbox" style="display:none" />'
			. Html::openElement( 'div', [
				'class' => 'toctitle',
				'lang' => $lang->getHtmlCode(),
				'dir' => $lang->getDir(),
				'style' => 'white-space:nowrap',
			] )
			. '<h2 id="subpagenavigation-mw-toc-heading" style="position:relative;top:auto">' . $title . '</h2>'
			. '<span class="toctogglespan">'
			. Html::label( '', 'subpagenavigation-toctogglecheckbox', [
				'class' => 'toctogglelabel',
			] )
			. '</span>'
			. '</div>'
			. $toc
			. "</div>";
	}

	/**
	 * @param Output $output
	 * @return bool|string
	 */
	public function getTree( $output ) {
		$this->output = $output;
		$title = $output->getTitle();

		$attr = [
			'class' => 'SubpageNavigationTreeTag'
		];

		// $attr['data-subpagenavigation-options'] = $this->getOptionsAsJsStructure();
		$attr['data-subpagenavigation-options'] = self::encodeOptions( [
			'namespace' => $title->getNamespace()
		], 'json' );

		$outText = Html::openElement( 'div', [ 'class' => '' ] );
		$outText .= Html::closeElement( 'div' );

		$outText .= $this->renderChildren( $title, false );

		$attr['class'] = $attr['class'] . ' subpageNavigation-tree mw-pt-translate-navigation noprint';

		return Html::rawElement( 'div', $attr, $outText );
	}

	/**
	 * @param Title $title
	 * @param bool $api false
	 * @return string
	 */
	public function renderChildren( Title $title, $api = false ) {
		$prefix = ( !$api ? '' : $title->getDBkey() );
		$namespace = $title->getNamespace();
		$limit = $GLOBALS['wgSubpageNavigationTreeLimit'];

		$limit_ = $limit + 1;
		$subpages = \SubpageNavigation::getSubpages( "$prefix/", $namespace, $limit_ );

		$threshold = ( count( $subpages ) === $limit_ );
		if ( $threshold ) {
			$subpages = array_slice( $subpages, 0, $limit );
		}

		$titlesText = [];
		foreach ( $subpages as $title_ ) {
			$titlesText[] = $title_->getText();
		}

		if ( !empty( $GLOBALS['wgSubpageNavigationShowCount'] ) ) {
			$dbr = wfGetDB( DB_REPLICA );
			$childrenCount = \SubpageNavigation::getChildrenCount( $dbr, $titlesText, $namespace );

		} else {
			$childrenCount = array_fill( 0, count( $subpages ), 0 );
		}

		$ret = '';
		foreach ( $subpages as $title_ ) {
			$ret .= $this->renderNodeInfo( $title, $title_, $api, array_shift( $childrenCount ) );
		}

		if ( $threshold ) {
			$specialPage = SpecialPage::getTitleFor( 'SubpageNavigationBrowse', !empty( $prefix ) ? $prefix : null );
			$ret .= Html::rawElement( 'div', [
					'class' => 'subpagenavigation-article-header-show-more'
				],
				$this->linkRenderer->makeKnownLink(
					$specialPage,
					wfMessage( 'subpagenavigation-list-show-all' )->plain(),
					[],
					[ 'namespace' => $namespace ]
				)
			);
		}

		return $ret;
	}

	/**
	 * @param Title $parentTitle null
	 * @param Title $title
	 * @param bool $api false
	 * @param int $count 0
	 * @return string
	 */
	public function renderNodeInfo( Title $parentTitle, Title $title, $api = false, $count = 0 ) {
		$count = (int)$count;
		$label = $title->getText();

		if ( $api ) {
			$label = substr( $label, strlen( $parentTitle->getText() ) + 1 );
		}

		$link = $this->linkRenderer->makeLink( $title,
			// *** important! zero width char, to ensure
			// links don't overflow the sidebar
			implode( '​', preg_split( '//', $label ) ) );

		$ret = '';

		# NOTE: things in CategoryTree.js rely on the exact order of tags!
		#      Specifically, the CategoryTreeChildren div must be the first
		#      sibling with nodeName = DIV of the grandparent of the expland link.

		$ret .= Html::openElement( 'div', [ 'class' => 'SubpageNavigationTreeSection' ] );
		$ret .= Html::openElement( 'div', [ 'class' => 'SubpageNavigationTreeItem' ] );

		$attr = [ 'class' => 'SubpageNavigationTreeBullet' ];

		$title_ = RequestContext::getMain()->getTitle();

		$expanded = strpos( ( $title_ ? $title_->getText() : '' ), $title->getText() ) === 0;

		if ( $count === 0 ) {
			$bullet = '';
			$attr['class'] = 'SubpageNavigationTreeEmptyBullet';

			// *** or
			$attr['class'] = 'SubpageNavigationTreePageBullet';

		} else {
			$linkattr = [
				'class' => 'SubpageNavigationTreeToggle',
				'data-subpagenavigation-title' => $title->getDbKey(),
			];

			if ( !$expanded ) {
				$linkattr['data-subpagenavigation-state'] = 'collapsed';
			} else {
				$linkattr['data-subpagenavigation-loaded'] = true;
				$linkattr['data-subpagenavigation-state'] = 'expanded';
			}

			$bullet = Html::element( 'span', $linkattr );
		}

		$ret .= Html::rawElement( 'span', $attr, $bullet ) . ' ';
		$ret .= $link;

		// && $this->getOption( 'showcount' )
		if ( $count !== 0 ) {
			$ret .= self::createCountString( RequestContext::getMain(), $count );
		}

		$children = 0;
		if ( $expanded ) {
			$children = $this->renderChildren( $title, true );
		}

		$ret .= Html::closeElement( 'div' );
		$ret .= Html::openElement(
			'div',
			[
				'class' => 'SubpageNavigationTreeChildren',
				'style' => $children === 0 ? 'display:none' : null
			]
		);

		if ( $expanded ) {
			$ret .= $children;
		}

		return $ret . Html::closeElement( 'div' ) . Html::closeElement( 'div' );
	}

	/**
	 * Add ResourceLoader modules to the OutputPage object
	 * @param OutputPage $outputPage
	 */
	public static function setHeaders( OutputPage $outputPage ) {
		# Add the modules
		$outputPage->addModuleStyles( 'ext.SubpageNavigation.styles' );
		$outputPage->addModules( 'ext.SubpageNavigation.tree' );
	}

	/**
	 * @param array $options
	 * @param string $enc
	 * @return mixed
	 * @throws Exception
	 */
	protected static function encodeOptions( array $options, $enc ) {
		if ( $enc === 'mode' || $enc === '' ) {
			$opt = $options['mode'];
		} elseif ( $enc === 'json' ) {
			$opt = FormatJson::encode( $options );
		} else {
			throw new Exception( 'Unknown encoding for SubpageNavigation options: ' . $enc );
		}

		return $opt;
	}

	/**
	 * @param IContextSource $context
	 * @param int $count
	 * @return string
	 */
	public static function createCountString( IContextSource $context, $count ) {
		$attr = [
			'title' => $context->msg( 'subpagenavigation-tree-member-counts' )
				->numParams( $count )->text(),
			# numbers and commas get messed up in a mixed dir env
			'dir' => $context->getLanguage()->getDir()
		];

		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		$ret = $contLang->getDirMark() . ' ';
		$ret .= Html::rawElement(
			'span',
			$attr,
			$context->msg( 'subpagenavigation-tree-member-num' )
				->params( $count )
				->escaped()
		);

		return $ret;
	}

	/**
	 * @param string $title
	 * @param int|null $namespace
	 * @return null|Title
	 */
	public static function makeTitle( $title, $namespace = null ) {
		$title = trim( strval( $title ) );

		if ( $title === '' ) {
			return null;
		}

		return Title::newFromText( $title, $namespace );
	}

}

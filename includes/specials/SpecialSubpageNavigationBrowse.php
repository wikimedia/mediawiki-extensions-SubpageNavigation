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

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;

class SpecialSubpageNavigationBrowse extends QueryPage {

	/** @var string */
	private $prefix;

	/** @var int */
	private $namespace;

	/** @var LinkRenderer */
	private $LinkRenderer;

	/** @var Title */
	private $title;

	/** @var MediaWiki\Html\Html|Html */
	private $HtmlClass;

	/**
	 * @inheritDoc
	 */
	public function __construct( $name = 'SubpageNavigationBrowse' ) {
		parent::__construct( $name, false );
		$this->HtmlClass = \SubpageNavigation::HtmlClass();
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		// ***edited
		// $this->checkPermissions();
		$this->setHeaders();
		$this->outputHeader();

		$out = $this->getOutput();
		$title = null;
		$parentTitle = null;

		if ( $par ) {
			$title = Title::newFromText( $par, $this->getRequest()->getVal( 'namespace' ) );
			$parentTitle = \SubpageNavigation::getFirstAncestor( $title );

			// remove namespace
			$this->prefix = $title->getDBkey() . '/';
		} else {
			$this->prefix = '/';
		}

		$this->title = $title;
		$this->addNavigationLinks();

		$multiplePages = ( $this->numRows > 20 || $this->offset > 0 );

		if ( $par ) {
			$attr = [];
			if ( !$parentTitle ) {
				$attr['style'] = 'font-style:italic';
			}
			$label = $parentTitle ? $parentTitle->getFullText()
				: $this->msg( 'subpagenavigation-specialsubpages-root' )->parse();

			$out->addHTML( $this->msg( 'subpagenavigation-specialsubpages-return',
				$this->getSpecialLink(
					$parentTitle,
					$label,
					(int)$this->getRequest()->getVal( 'mode' ),
					(int)$this->getRequest()->getVal( 'namespace' ),
					$attr )
				)->text()
			);

			if ( $multiplePages ) {
				$out->addHTML( '<br />' );
			}
		}

		if ( $title ) {
			$this->namespace = $title->getNamespace();
		} elseif ( !empty( $this->getRequest()->getVal( 'namespace' ) ) ) {
			$this->namespace = (int)$this->getRequest()->getVal( 'namespace' );
		} else {
			$this->namespace = NS_MAIN;
		}

		$this->LinkRenderer = $this->getLinkRenderer();

		if ( $this->isCached() && !$this->isCacheable() ) {
			$out->addWikiMsg( 'querypage-disabled' );
			return;
		}

		$out->setSyndicated( $this->isSyndicated() );

		if ( $this->limit == 0 && $this->offset == 0 ) {
			[ $this->limit, $this->offset ] = $this->getLimitOffset();
		}
		$dbLimit = $this->getDBLimit( $this->limit, $this->offset );
		// @todo Use doQuery()
		if ( !$this->isCached() ) {
			// select one extra row for navigation
			$res = $this->reallyDoQuery( $dbLimit, $this->offset );
		} else {
			// Get the cached result, select one extra row for navigation
			$res = $this->fetchFromCache( $dbLimit, $this->offset );
			if ( !$this->listoutput ) {
				// Fetch the timestamp of this update
				$ts = $this->getCachedTimestamp();
				$lang = $this->getLanguage();
				$maxResults = $lang->formatNum( $this->getConfig()->get(
					// ***edited
					class_exists( 'MainConfigNames' ) ? MainConfigNames::QueryCacheLimit : 'QueryCacheLimit' ) );

				if ( $ts ) {
					$user = $this->getUser();
					$updated = $lang->userTimeAndDate( $ts, $user );
					$updateddate = $lang->userDate( $ts, $user );
					$updatedtime = $lang->userTime( $ts, $user );
					$out->addMeta( 'Data-Cache-Time', $ts );
					$out->addJsConfigVars( 'dataCacheTime', $ts );
					$out->addWikiMsg( 'perfcachedts', $updated, $updateddate, $updatedtime, $maxResults );
				} else {
					$out->addWikiMsg( 'perfcached', $maxResults );
				}

				// If updates on this page have been disabled, let the user know
				// that the data set won't be refreshed for now
				$disabledQueryPages = self::getDisabledQueryPages( $this->getConfig() );
				if ( isset( $disabledQueryPages[$this->getName()] ) ) {
					$runMode = $disabledQueryPages[$this->getName()];
					if ( $runMode === 'disabled' ) {
						$out->wrapWikiMsg(
							"<div class=\"mw-querypage-no-updates\">\n$1\n</div>",
							'querypage-no-updates'
						);
					} else {
						// Messages used here: querypage-updates-periodical
						$out->wrapWikiMsg(
							"<div class=\"mw-querypage-updates-" . $runMode . "\">\n$1\n</div>",
							'querypage-updates-' . $runMode
						);
					}
				}
			}
		}

		$this->numRows = $res->numRows();

		$dbr = $this->getRecacheDB();
		$this->preprocessResults( $dbr, $res );

		$out->addHTML( $this->HtmlClass::openElement( 'div', [ 'class' => 'mw-spcontent' ] ) );

		// Top header and navigation
		// ***edited
		if ( $this->numRows > 20 || $this->offset > 0 ) {
		// if ( $this->shownavigation ) {
			$out->addHTML( $this->getPageHeader() );
			if ( $this->numRows > 0 ) {
				$out->addHTML( $this->msg( 'showingresultsinrange' )->numParams(
					min( $this->numRows, $this->limit ), // do not show the one extra row, if exist
					$this->offset + 1, ( min( $this->numRows, $this->limit ) + $this->offset ) )->parseAsBlock() );
				// Disable the "next" link when we reach the end
				$miserMaxResults = $this->getConfig()->get(
					// ***edited
					class_exists( 'MainConfigNames' ) ? MainConfigNames::MiserMode : 'MiserMode' )
					&& ( $this->offset + $this->limit >= $this->getMaxResults() );
				$atEnd = ( $this->numRows <= $this->limit ) || $miserMaxResults;
				$paging = $this->buildPrevNextNavigation( $this->offset,
					$this->limit, $this->linkParameters(), $atEnd, $par );
				$out->addHTML( '<p>' . $paging . '</p>' );
			} else {
				// No results to show, so don't bother with "showing X of Y" etc.
				// -- just let the user know and give up now
				$this->showEmptyText();
				$out->addHTML( $this->HtmlClass::closeElement( 'div' ) );
				return;
			}
		}

		if ( $par ) {
			$out->addHTML( '<h4>' . $this->LinkRenderer->makeKnownLink(
				$title, $title->getText() ) . '</h4>' );
		}

		// The actual results; specialist subclasses will want to handle this
		// with more than a straight list, so we hand them the info, plus
		// an OutputPage, and let them get on with it
		$this->outputResults( $out,
			$this->getSkin(),
			$dbr, // Should use IResultWrapper for this
			$res,
			min( $this->numRows, $this->limit ), // do not format the one extra row, if exist
			$this->offset );

		// Repeat the paging links at the bottom
		// ***edited
		if ( $this->numRows > 20 || $this->offset > 0 ) {
		// if ( $this->shownavigation ) {
			// @phan-suppress-next-line PhanPossiblyUndeclaredVariable paging is set when used here
			$out->addHTML( '<p>' . $paging . '</p>' );
		}

		$out->addHTML( $this->HtmlClass::closeElement( 'div' ) );
	}

	/**
	 * @inheritDoc
	 */
	public function getQueryInfo() {
		// @phan-suppress-next-line PhanTypeMismatchReturnProbablyReal null needed for b/c checks
		return null;
	}

	/**
	 * @param Title $title
	 * @param string $label
	 * @param int $mode
	 * @param int $namespace
	 * @param array $attr
	 * @return string
	 */
	private function getSpecialLink( $title, $label, $mode, $namespace, $attr = [] ) {
		$specialPage = SpecialPage::getTitleFor( 'SubpageNavigationBrowse', $title ? $title->getDBkey() : null );

		return $this->HtmlClass::rawElement( 'a', array_merge( [
			'href' => wfAppendQuery( $specialPage->getLocalURL(), 'mode=' . $mode . '&namespace=' . $namespace )
		], $attr ), HtmlArmor::getHtml( $label ) );
	}

	/**
	 * @see AbuseFilterSpecialPage
	 */
	protected function addNavigationLinks() {
		$formattedNamespaces = MediaWikiServices::getInstance()
			->getContentLanguage()->getFormattedNamespaces();

		$links = [];
		foreach ( $formattedNamespaces as $mode => $name ) {
			if ( $mode < 0 || $mode % 2 !== 0 ) {
				continue;
			}
			$msg = !empty( $name ) ? $name : 'Main';

			if ( $mode === (int)$this->getRequest()->getVal( 'namespace' ) ) {
				$links[] = $this->HtmlClass::element( 'strong', [], $msg );
			} else {
				$links[] = $this->getSpecialLink( $this->title, $msg, (int)$this->getRequest()->getVal( 'mode' ), $mode );
			}
		}

		$linkStrNamespace = $this->msg( 'parentheses' )
			->rawParams( $this->getLanguage()->pipeList( $links ) )
			->text();
		$linkStrNamespace = $this->msg( 'subpagenavigation-browse-topnav-namespace' )->parse() . " $linkStrNamespace";
		$linkStrNamespace = $this->HtmlClass::rawElement( 'div', [ 'class' => 'mw-subpagenavigation-browse-navigation' ],
			$linkStrNamespace );

		$linkDefs = [
			'default' => 1,
			'folders' => 2,
			'filesystem' => 3,
		];

		$links = [];
		foreach ( $linkDefs as $name => $mode ) {
			// Give grep a chance to find the usages:
			// subpagenavigation-browse-default, subpagenavigation-browse-folders, subpagenavigation-browse-filesystem,
			$msgName = "subpagenavigation-browse-$name";
			$msg = $this->msg( $msgName )->parse();

			if ( $mode === (int)$this->getRequest()->getVal( 'mode' )
				|| ( empty( $this->getRequest()->getVal( 'mode' ) )
					&& $mode === \SubpageNavigation::MODE_DEFAULT ) ) {
				$links[] = $this->HtmlClass::rawElement( 'strong', [], $msg );
			} else {
				$links[] = $this->getSpecialLink( $this->title, $msg, $mode, (int)$this->getRequest()->getVal( 'namespace' ) );
			}
		}

		$linkStrMode = $this->msg( 'parentheses' )
			->rawParams( $this->getLanguage()->pipeList( $links ) )
			->text();
		$linkStrMode = $this->msg( 'subpagenavigation-browse-topnav' )->parse() . " $linkStrMode";
		$linkStrMode = $this->HtmlClass::rawElement( 'div', [ 'class' => 'mw-subpagenavigation-browse-navigation' ], $linkStrMode );

		$this->getOutput()->setSubtitle( $linkStrMode . '<br/>' . $linkStrNamespace );
	}

	/**
	 * @inheritDoc
	 */
	protected function buildPrevNextNavigation(
		$offset,
		$limit,
		array $query = [],
		$atend = false,
		$subpage = false
	) {
		$ret = parent::buildPrevNextNavigation( $offset, $limit, $query, $atend, $subpage );

		$html = new DOMDocument();
		$html->loadHTML( $ret );
		$request = $this->getRequest();
		// $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
		foreach ( $html->getElementsByTagName( 'a' ) as $node ) {
			$href = $node->getAttribute( 'href' );
			$node->setAttribute( 'href', "$href&mode=" . $request->getVal( 'mode' )
				. '&namespace=' . $request->getVal( 'namespace' ) );
		}
		return $html->saveHtml();
	}

	/**
	 * @inheritDoc
	 */
	public function reallyDoQuery( $limit, $offset = false ) {
		$fname = static::class . '::reallyDoQuery';
		// $dbr = $this->getRecacheDB();
		$dbr = \SubpageNavigation::getDB( DB_PRIMARY );

		$mode = $this->getRequest()->getVal( 'mode' );
		if ( empty( $mode ) ) {
			$mode = \SubpageNavigation::MODE_DEFAULT;
		}

		$query = \SubpageNavigation::subpagesSQL( $dbr, $this->prefix, $this->namespace, (int)$mode );

		$sql = $dbr->limitResult( $query, $limit, $offset );

		$res = $dbr->query( $sql, $fname );
		$titlesText = [];
		foreach ( $res as $row ) {
			$titlesText[] = $row->page_title;
		}

		// set $countLimit as SubpageNavigationArticleHeaderSubpagesLimit if not set
		$countLimit = \SubpageNavigation::getSetGlobalLimit( 'SubpageNavigationCountSubpagesLimit', 100 );

		$childrenCount = \SubpageNavigation::getChildrenCount( $dbr, $titlesText, $this->namespace, $countLimit );

		foreach ( $res as $i => $row ) {
			$titlesText[] = $row->page_title;
		}
		$ret = [];
		foreach ( $res as $row ) {
			$row->childCount = array_shift( $childrenCount );
			$ret[] = $row;
		}
		// $res->rewind();
		return new Wikimedia\Rdbms\FakeResultWrapper( $ret );
	}

	/**
	 * @inheritDoc
	 */
	public function formatResult( $skin, $result ) {
		$title = Title::newFromRow( $result );
		$prefix = $this->prefix;
		if ( $prefix == '/' ) {
			$prefix = '';
		}
		$display_title = ( !empty( $result->display_title ) ? $result->display_title :
			substr( $title->getText(), strlen( $prefix ) ) );

		if ( $result->childCount > 0 ) {
			// $specialPage = SpecialPage::getTitleFor( 'subpagenavigationbrowse', $title->getDBkey() );
			// $this->LinkRenderer->makeKnownLink( $specialPage, $display_title . ' (' . $result->childCount . ')';
			$attr = [
				'style' => 'font-weight:bold'
			];
			$msg = $display_title . ' (' . $result->childCount . ')';
			return $this->getSpecialLink( $title, $msg, (int)$this->getRequest()->getVal( 'mode' ), $this->namespace, $attr );
		}

		return $this->LinkRenderer->makeKnownLink( $title, $display_title );
	}

	/**
	 * @inheritDoc
	 * @see QueryPage
	 */
	protected function outputResults( $out, $skin, $dbr, $res, $num, $offset ) {
		if ( $num > 0 ) {
			$html = [];
			if ( !$this->listoutput ) {
				$html[] = $this->openList( $offset );
			}

			// $res might contain the whole 1,000 rows, so we read up to
			// $num [should update this to use a Pager]
			// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
			for ( $i = 0; $i < $num && $row = $res->fetchObject(); $i++ ) {
				$line = $this->formatResult( $skin, $row );
				if ( $line ) {
					// ***edited
					$class = ( $row->childCount ? ' class="folder"' : '' );
					$html[] = $this->listoutput
						? $line
						: "<li$class>{$line}</li>\n";
				}
			}

			if ( !$this->listoutput ) {
				$html[] = $this->closeList();
			}

			$html = $this->listoutput
				? $this->getContentLanguage()->listToText( $html )
				: implode( '', $html );

			$out->addHTML( $html );
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function openList( $offset ) {
		return "\n<ul class='special directory-list'>\n";
	}

	/**
	 * @inheritDoc
	 */
	protected function closeList() {
		return "</ul>\n";
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'subpagenavigation';
	}
}

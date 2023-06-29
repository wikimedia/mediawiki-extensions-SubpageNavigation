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

use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IResultWrapper;

class SpecialSubpageNavigationSubpages extends QueryPage {

	/** @var prefix */
	private $prefix;
	
	/** @var namespace */
	private $namespace;
	
	/** @var LinkRenderer */
	private $LinkRenderer;
	
	/**
	 * @inheritDoc
	 */
	public function __construct( $name = 'subpagenavigationsubpages' ) {
		parent::__construct( $name, false );
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
		
		$multiplePages = ( $this->numRows > 20 || $this->offset > 0 );		
		
		if ( $par ) {
			$title = Title::newFromText( $par );
			$parentTitle = \SubpageNavigation::getFirstAncestor( $title );
			$specialPage = SpecialPage::getTitleFor( 'subpagenavigationsubpages', $parentTitle ? $parentTitle->getDBkey() : null );
			
			$out->addWikiMsg(
				'subpagenavigation-specialsubpages-return',
				$specialPage->getFullText(),
				$parentTitle ? $parentTitle->getFullText() : $this->msg( 'subpagenavigation-specialsubpages-root' )->parse()
			);
			if ( $multiplePages ) {
				$out->addHTML( '<br />' );
			}
		}
		
		$this->prefix = $par . '/';
		$this->namespace = $title ? $title->getNamespace() : NS_MAIN;
		$this->LinkRenderer = $this->getLinkRenderer();

		if ( $this->isCached() && !$this->isCacheable() ) {
			$out->addWikiMsg( 'querypage-disabled' );
			return;
		}

		$out->setSyndicated( $this->isSyndicated() );

		if ( $this->limit == 0 && $this->offset == 0 ) {
			list( $this->limit, $this->offset ) = $this->getLimitOffset();
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
					MainConfigNames::QueryCacheLimit ) );

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

		$out->addHTML( Xml::openElement( 'div', [ 'class' => 'mw-spcontent' ] ) );

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
				$miserMaxResults = $this->getConfig()->get( MainConfigNames::MiserMode )
					&& ( $this->offset + $this->limit >= $this->getMaxResults() );
				$atEnd = ( $this->numRows <= $this->limit ) || $miserMaxResults;
				$paging = $this->buildPrevNextNavigation( $this->offset,
					$this->limit, $this->linkParameters(), $atEnd, $par );
				$out->addHTML( '<p>' . $paging . '</p>' );
			} else {
				// No results to show, so don't bother with "showing X of Y" etc.
				// -- just let the user know and give up now
				$this->showEmptyText();
				$out->addHTML( Xml::closeElement( 'div' ) );
				return;
			}
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

		$out->addHTML( Xml::closeElement( 'div' ) );
	}
	
	/**
	 * @inheritDoc
	 */
	public function reallyDoQuery( $limit, $offset = false ) {
		$fname = static::class . '::reallyDoQuery';
		$dbr = $this->getRecacheDB();
		// $dbr = wfGetDB( DB_MASTER );
		$query = \SubpageNavigation::subpagesSQL( $dbr,  $this->prefix, $this->namespace );
		$sql = $dbr->limitResult( $query, $limit, $offset );

		$res = $dbr->query( $sql, $fname );

		return $res;
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
			$specialPage = SpecialPage::getTitleFor( 'subpagenavigationsubpages', $title->getDBkey() );
			return '<span style="font-weight:bold">' . $this->LinkRenderer->makeKnownLink( $specialPage, $display_title . ' (' . $result->childCount . ')' )
				. '<span>';
		}
		
		return $this->LinkRenderer->makeKnownLink( $title, $display_title );
	}

	/**
	 * @inheritDoc
	 */
	protected function openList($offset) {
		return "\n<ul class='special'>\n";
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
	protected function getGroupName()
	{
		return 'subpagenavigation';
	}
}


<?php

namespace MediaWiki\Extension\SubpageNavigation;

use ApiBase;
use ApiMain;
// use Config;
use ConfigFactory;
use FormatJson;
// use MediaWiki\Languages\LanguageConverterFactory;
use Wikimedia\ParamValidator\ParamValidator;

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

// @credits: https://www.mediawiki.org/wiki/Extension:CategoryTree

class Api extends ApiBase {
	/** @var ConfigFactory */
	private $configFactory;

	/**
	 * @param ApiMain $main
	 * @param string $action
	 * @param ConfigFactory $configFactory
	 */
	public function __construct(
		ApiMain $main,
		$action,
		ConfigFactory $configFactory
	) {
		parent::__construct( $main, $action );
		$this->configFactory = $configFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$options = $this->extractOptions( $params );
		$title = Tree::makeTitle( $params['title'], (int)$options['namespace'] );

		if ( !$title || $title->isExternal() ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['title'] ) ] );
		}

		$tree = new Tree( $options );
		// $config = $this->configFactory->makeConfig( 'subpagenavigation' );

		$html = trim( $tree->renderChildren( $title, true ) );

		// $this->getMain()->setCacheMode( 'public' );
		$this->getResult()->addContentValue( $this->getModuleName(), 'html', $html );
	}

	/**
	 * @param array $params
	 * @return array
	 */
	private function extractOptions( $params ) {
		$options = [];
		if ( isset( $params['options'] ) ) {
			$options = FormatJson::decode( $params['options'] );
			if ( !is_object( $options ) ) {
				$this->dieWithError( 'apierror-subpagenavigation-invalidjson', 'invalidjson' );
			}
			$options = get_object_vars( $options );
		}
		return $options;
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		return [
			'title' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'options' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function isInternal() {
		return true;
	}
}

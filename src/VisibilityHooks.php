<?php

namespace MediaWiki\Extension\MixedVisibilityFiles;

use Config;
use MediaWiki\Hook\GetDoubleUnderscoreIDsHook;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsExpensiveHook;
use MediaWiki\User\UserGroupManager;
use PageProps;

class VisibilityHooks implements
	GetDoubleUnderscoreIDsHook,
	GetUserPermissionsErrorsExpensiveHook
{

	private PageProps $pageProps;

	private UserGroupManager $userGroupManager;

	/** @var array<string,string|string[]> */
	private array $prefixRestrictions;

	public function __construct(
		PageProps $pageProps,
		UserGroupManager $userGroupManager,
		Config $config
	) {
		$this->pageProps = $pageProps;
		$this->userGroupManager = $userGroupManager;
		$this->prefixRestrictions = $config->get( 'MixedVisibilityFilesPrefixRestrictions' );
	}

	/**
	 * @param array &$doubleUnderscoreIDs
	 */
	public function onGetDoubleUnderscoreIDs( &$doubleUnderscoreIDs ) {
		$doubleUnderscoreIDs[] = 'makeFilePublic';
	}

	/** @inheritDoc */
	public function onGetUserPermissionsErrorsExpensive( $title, $user, $action,
		&$result
	) {
		// Most of the time we aren't interested in doing anything, simplest
		// checks first
		if ( MW_ENTRY_POINT !== 'img_auth' ) {
			return;
		}
		// Only trying to affect file reads
		if ( $action !== 'read' ) {
			return;
		}
		if ( $title->getNamespace() !== NS_FILE ) {
			return;
		}

		// Check prefix-based group restrictions (applies to all users)
		$fileName = $title->getDBkey();
		foreach ( $this->prefixRestrictions as $prefix => $requiredGroups ) {
			if ( str_starts_with( $fileName, $prefix ) ) {
				// File matches a prefix restriction
				if ( !$user->isRegistered() ) {
					$result = false;
					return false;
				}
				$userGroups = $this->userGroupManager->getUserEffectiveGroups( $user );
				$allowedGroups = (array)$requiredGroups;
				if ( !array_intersect( $allowedGroups, $userGroups ) ) {
					$result = false;
					return false;
				}
				// User is in a required group, allow access
				return;
			}
		}

		// Default behavior: block anonymous users unless file is marked public
		if ( $user->isRegistered() ) {
			return;
		}

		$allProps = $this->pageProps->getAllProperties( $title );
		if ( $allProps && $allProps[$title->getId()] ) {
			$props = $allProps[$title->getId()];
			if ( array_key_exists( 'makeFilePublic', $props ) ) {
				// It is public, nothing to do
				return;
			}
		}

		// Anonymous user is trying to read a file not marked as public
		// Even if we tried returning a nice error in $result it would be
		// ignored by img_auth.php, but we need to set something or otherwise
		// the hook is considered a success even if we return false
		$result = false;
		return false;
	}

}

<?php

namespace Pr4w\SocialTokens\Contracts;

/**
 * Optional, with ListsReachableAccounts: the connector's isStillReachable()
 * asks the provider about the missing account itself (Facebook reads the
 * Instagram account with the user token), so its "no" is a second sign,
 * independent of the list, and check-static flags the account in the run
 * that finds it missing.
 *
 * Without it, the list is the only sign (LinkedIn's organization roles): an
 * account missing from it is flagged only once it has been missing for a
 * day, in two runs at least 20 hours apart, so that a list the provider
 * serves incomplete for a few hours cuts nobody off.
 */
interface VerifiesMissingAccounts {}

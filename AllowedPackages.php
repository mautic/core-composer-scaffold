<?php

namespace Mautic\Composer\Plugin\Scaffold;

use Composer\Composer;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

/**
 * Determine recursively which packages have been allowed to scaffold files.
 *
 * If the root-level composer.json allows mautic/core-lib, and mautic/core-lib allows
 * mautic/assets, then the later package will also implicitly be allowed.
 *
 * @internal
 */
class AllowedPackages implements PostPackageEventListenerInterface {

  /**
   * The Composer service.
   *
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * Composer's I/O service.
   *
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * Manager of the options in the top-level composer.json's 'extra' section.
   *
   * @var \Mautic\Composer\Plugin\Scaffold\ManageOptions
   */
  protected $manageOptions;

  /**
   * The list of new packages added by this Composer command.
   *
   * @var array
   */
  protected $newPackages = [];

  /**
   * AllowedPackages constructor.
   *
   * @param \Composer\Composer $composer
   *   The composer object.
   * @param \Composer\IO\IOInterface $io
   *   IOInterface to write to.
   * @param \Mautic\Composer\Plugin\Scaffold\ManageOptions $manage_options
   *   Manager of the options in the top-level composer.json's 'extra' section.
   */
  public function __construct(Composer $composer, IOInterface $io, ManageOptions $manage_options) {
    $this->composer = $composer;
    $this->io = $io;
    $this->manageOptions = $manage_options;
  }

  /**
   * Gets a list of all packages that are allowed to copy scaffold files.
   *
   * We will implicitly allow the projects 'mautic/legacy-scaffold-assets'
   * and 'mautic/core-lib' to scaffold files, if they are present. Any other
   * project must be explicitly whitelisted in the top-level composer.json
   * file in order to be allowed to override scaffold files.
   * Configuration for packages specified later will override configuration
   * specified by packages listed earlier. In other words, the last listed
   * package has the highest priority. The root package will always be returned
   * at the end of the list.
   *
   * @return \Composer\Package\PackageInterface[]
   *   An array of allowed Composer packages.
   */
  public function getAllowedPackages() {
    $top_level_packages = $this->getTopLevelAllowedPackages();
    $allowed_packages = $this->recursiveGetAllowedPackages($top_level_packages);
    // If the root package defines any file mappings, then implicitly add it
    // to the list of allowed packages. Add it at the end so that it overrides
    // all the preceding packages.
    if ($this->manageOptions->getOptions()->hasFileMapping()) {
      $root_package = $this->composer->getPackage();
      unset($allowed_packages[$root_package->getName()]);
      $allowed_packages[$root_package->getName()] = $root_package;
    }
    // Handle any newly-added packages that are not already allowed.
    return $this->evaluateNewPackages($allowed_packages);
  }

  /**
   * {@inheritdoc}
   */
  public function event(PackageEvent $event) {
    $operation = $event->getOperation();
    // Determine the package. Later, in evaluateNewPackages(), we will report
    // which of the newly-installed packages have scaffold operations, and
    // whether or not they are allowed to scaffold by the allowed-packages
    // option in the root-level composer.json file.
    $operationType = $this->getOperationType($operation);
    $package = $operationType === 'update' ? $operation->getTargetPackage() : $operation->getPackage();
    if (ScaffoldOptions::hasOptions($package->getExtra())) {
      $this->newPackages[$package->getName()] = $package;
    }
  }

  /**
   * Gets all packages that are allowed in the top-level composer.json.
   *
   * We will implicitly allow the projects 'mautic/legacy-scaffold-assets'
   * and 'mautic/core-lib' to scaffold files, if they are present. Any other
   * project must be explicitly whitelisted in the top-level composer.json
   * file in order to be allowed to override scaffold files.
   *
   * @return array
   *   An array of allowed Composer package names.
   */
  protected function getTopLevelAllowedPackages() {
    $implicit_packages = [
      'mautic/core-lib'
    ];
    $top_level_packages = $this->manageOptions->getOptions()->allowedPackages();
    return array_merge($implicit_packages, $top_level_packages);
  }

  /**
   * Builds a name-to-package mapping from a list of package names.
   *
   * @param string[] $packages_to_allow
   *   List of package names to allow.
   * @param array $allowed_packages
   *   Mapping of package names to PackageInterface of packages already
   *   accumulated.
   *
   * @return \Composer\Package\PackageInterface[]
   *   Mapping of package names to PackageInterface in priority order.
   */
  protected function recursiveGetAllowedPackages(array $packages_to_allow, array $allowed_packages = []) {
    foreach ($packages_to_allow as $name) {
      $package = $this->getPackage($name);
      if ($package instanceof PackageInterface && !isset($allowed_packages[$name])) {
        $allowed_packages[$name] = $package;
        $package_options = $this->manageOptions->packageOptions($package);
        $allowed_packages = $this->recursiveGetAllowedPackages($package_options->allowedPackages(), $allowed_packages);
      }
    }
    return $allowed_packages;
  }

  /**
   * Evaluates newly-added packages and see if they are already allowed.
   *
   * For now we will only emit warnings if they are not.
   *
   * @param array $allowed_packages
   *   Mapping of package names to PackageInterface of packages already
   *   accumulated.
   *
   * @return \Composer\Package\PackageInterface[]
   *   Mapping of package names to PackageInterface in priority order.
   */
  protected function evaluateNewPackages(array $allowed_packages) {
    foreach ($this->newPackages as $name => $newPackage) {
      if (!array_key_exists($name, $allowed_packages)) {
        $this->io->write("Not scaffolding files for <comment>{$name}</comment>, because it is not listed in the element 'extra.mautic-scaffold.allowed-packages' in the root-level composer.json file.");
      }
      else {
        $this->io->write("Package <comment>{$name}</comment> has scaffold operations, and is already allowed in the root-level composer.json file.");
      }
    }
    // @todo We could prompt the user and ask if they wish to allow a
    // newly-added package. This might be useful if, for example, the user
    // might wish to require an installation profile that contains scaffolded
    // assets. For more information, see:
    // https://www.drupal.org/project/drupal/issues/3064990
    return $allowed_packages;
  }

  /**
   * Determine the type of the provided operation.
   *
   * Adjusts API used for Composer 1 or Composer 2.
   *
   * @param \Composer\DependencyResolver\Operation\OperationInterface $operation
   *   The operation object.
   *
   * @return string
   *   The operation type.
   */
  protected function getOperationType(OperationInterface $operation) {
    // Use Composer 2 method.
    if (method_exists($operation, 'getOperationType')) {
      return $operation->getOperationType();
    }
    // Fallback to Composer 1 method.
    return $operation->getJobType();
  }

  /**
   * Retrieves a package from the current composer process.
   *
   * @param string $name
   *   Name of the package to get from the current composer installation.
   *
   * @return \Composer\Package\PackageInterface|null
   *   The Composer package.
   */
  protected function getPackage($name) {
    return $this->composer->getRepositoryManager()->getLocalRepository()->findPackage($name, '*');
  }

}

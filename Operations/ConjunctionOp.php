<?php

namespace Mautic\Composer\Plugin\Scaffold\Operations;

use Composer\IO\IOInterface;
use Mautic\Composer\Plugin\Scaffold\ScaffoldFilePath;
use Mautic\Composer\Plugin\Scaffold\ScaffoldOptions;

/**
 * Joins two operations on the same file into a single operation.
 *
 * @internal
 */
class ConjunctionOp extends AbstractOperation {

  /**
   * The first operation.
   *
   * @var \Mautic\Composer\Plugin\Scaffold\Operations\OperationInterface
   */
  protected $firstOperation;

  /**
   * The second operation.
   *
   * @var \Mautic\Composer\Plugin\Scaffold\Operations\OperationInterface
   */
  protected $secondOperation;

  /**
   * ConjunctionOp constructor.
   *
   * @param \Mautic\Composer\Plugin\Scaffold\Operations\OperationInterface $first_operation
   * @param \Mautic\Composer\Plugin\Scaffold\Operations\OperationInterface $second_operation
   */
  public function __construct(OperationInterface $first_operation, OperationInterface $second_operation) {
    $this->firstOperation = $first_operation;
    $this->secondOperation = $second_operation;
  }

  /**
   * {@inheritdoc}
   */
  public function process(ScaffoldFilePath $destination, IOInterface $io, ScaffoldOptions $options) {
    $destination_path = $destination->fullPath();
    // First, scaffold the original file. Disable symlinking, because we
    // need a copy of the file if we're going to append / prepend to it.
    @unlink($destination_path);
    $this->firstOperation->process($destination, $io, $options->overrideSymlink(FALSE));
    return $this->secondOperation->process($destination, $io, $options);
  }

}

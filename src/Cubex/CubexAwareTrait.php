<?php
namespace Cubex;

trait CubexAwareTrait
{
  protected $_cubex;

  /**
   * Set the cubex application
   *
   * @param Cubex $app
   */
  public function setCubex(Cubex $app)
  {
    $this->_cubex = $app;
  }

  /**
   * Retrieve the cubex application
   *
   * @return mixed
   *
   * @throws \RuntimeException
   */
  public function getCubex()
  {
    if($this->_cubex === null)
    {
      throw new \RuntimeException("The cubex application has not been set", 404);
    }
    return $this->_cubex;
  }
}
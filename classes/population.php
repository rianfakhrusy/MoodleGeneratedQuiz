<?php
# The MIT License
#
# Copyright (c) 2017 Rian Fakhrusy
# Extended from Carlos André Ferrari's code (2011).
#
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to whom the Software is
# furnished to do so, subject to the following conditions:
#
# The above copyright notice and this permission notice shall be included in
# all copies or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
# THE SOFTWARE.

#namespace mod_gnrquiz;
defined('MOODLE_INTERNAL') || die();

/**
 * A class representing a population for a genetic algorithm simulation.
 *
 *  A population is simply a sorted collection of chromosomes
 *  (sorted by fitness) that has a convenience method for evolution.  This
 *  implementation of a population uses a tournament selection algorithm for
 *  selecting parents for crossover during each generation's evolution.
 *
 *  Note that this object is mutable, and calls to the evolve()
 *  method will generate a new collection of chromosome objects.
 *  Extended from Carlos André Ferrari's code.
 *
 * @author Rian Fakhrusy
 */

ini_set('max_execution_time', 300); //5 minutes php run timeout

class population {
    
    private $tournamentSize = 3;

    public $crossover;
    public $elitism;
    public $mutation;

    public $population = array();

    public $maxSize;
    public $currentSize;

    public function __construct($size = 512, $crossover = .8, $elitism=0.1, $mutation=0.03)
    {
        $this->crossover = $crossover;
        $this->elitism = $elitism;
        $this->mutation = $mutation;
        $this->maxSize = $this->currentSize = $size;
        while ($size--)
            $this->population[] = chromosome::genRandom();

        $this->sortPopulation();
    }

    /*
     * Sort the population by fitness, biggest value first, descending
     */
    private function sortPopulation(){
        rsort($this->population);
    }

    /*
     * A helper method used to select a random chromosome from the
     * population using a tournament selection algorithm.
     */
    private function tournamentSelection()
    {
        $best = $this->population[rand(0, $this->currentSize-1)];
        foreach (range(0, $this->tournamentSize) as $i){
            $cont = $this->population[rand(0, $this->currentSize-1)];
            if ($cont->fitness < $best->fitness) $best = $cont;
        }
        return $best;
    }

    /*
     * A helper method used to select two parents from the population using a
     * tournament selection algorithm.
     */
    private function selectParents()
    {
        return array($this->tournamentSelection(), $this->tournamentSelection());
    }

    /*
     * A helper method that return a random bolean value 
     * based on the ratio given as parameter
     */
    private function should($what){
        return (rand(0, 1000) / 1000) < $what;
    }

    /*
     * ethod to evolve the population of chromosomes.
     * I've made a change here to this method work like "Sparta"
     * it just discard the non eletive part of the population
     * before doing the mates =) so we get only the best chromosomes from
     * the last evolution based on the elitism
     */
    public function evolve()
    {
        $elite = $this->maxSize * $this->elitism;
        $this->population = array_slice($this->population, 0, $elite);
        $this->currentSize = count($this->population);
        while ($this->currentSize < $this->maxSize){
            if ($this->should($this->crossover)){
                list($p1, $p2) = $this->selectParents();
                $children = $p1->mate($p2);
                foreach ($children as $c){
                    $this->population[] = $this->should($this->mutation) ?
                                $c->mutate() : $c;
                    $this->currentSize++;
                }
                continue;
            }
            $this->population[] = $this->should($this->mutation) ?
                        $this->population[$this->currentSize-1]->mutate() : $this->population[$this->currentSize-1];
            $this->currentSize++;
        }
        $this->population;
        $this->sortPopulation();
    }
    
}
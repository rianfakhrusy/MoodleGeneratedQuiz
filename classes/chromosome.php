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

namespace mod_gnrquiz;
defined('MOODLE_INTERNAL') || die();

/**
 * A php script that auto-generate a quiz from question bank using
 * genetic algorithms.
 * Extended from Carlos André Ferrari's code.
 *
 * @author Rian Fakhrusy
 */
class chromosome {

    public $fitness;
    public $gene = [];

    /*
     * This class is used to define a chromosome for the gentic algorithm
     * simulation.

     * This class is essentially nothing more than a container for the details
     * of the chromosome, namely the gene (the string that represents our
     * target string) and the fitness (how close the gene is to the target
     * string).

     * Note that this class is immutable.  Calling mate() or mutate() will
     * result in a new chromosome instance being created.

     */
    public function __construct($gene,$allquestions)
    {
        $this->gene = $gene;
        $this->fitness = $this->calculateFitness($gene,$allquestions);
    }

    /*
     * Method used to mate the chromosome with another chromosome,
     * resulting in a new chromosome being returned.
     */
    public function mate(chromosome $mate)
    {
        #Convert the genes to arrays to make things easier.
        $arr1  = $this->gene;
        $arr2  = $mate->gene;

        #Store array size in variable to make things easier.
        $length = count($mate->gene);

        #Select a random pivot point for the mating
        $pivot = rand(0, count($this->gene)-2);

        #Copy the data from each gene to the first child.
        $child1 = array_merge(
            array_slice($arr1,0,$pivot), 
            array_slice($arr2,-$length+$pivot)
        );

        #Repeat for the second child, but in reverse order.
        $child2 = array_merge(
            array_slice($arr2,0,$pivot), 
            array_slice($arr1,-$length+$pivot)
        );

        return array(
            new self($child1),
            new self($child2)
        );
    }

    /*
     * Method used to generate a new chromosome based on a change in a
     * random character in the gene of this chromosome.  A new chromosome
     * will be created, but this original will not be affected.
     */
    public function mutate()
    {
        #get array of all question in question bank id
        $quizIds = array_map(function($o) { return $o->getId(); }, Quiz::$question); 

        $gene = $this->gene;
        $unusedGene = array_diff($quizIds, $gene); #get all question id that has not been in the quiz
        #remember that a quiz can not contain 2 of the same question

        #replace a random gene with a random new question that is represented by a gene
        shuffle($unusedGene);
        $gene{rand(0, count($gene)-1)} = $unusedGene[0];

        return new self($gene);
    }

    /*
     * Helper method used to return the fitness for the chromosome based
     * on its gene.
     */
    public function calculateFitness($gene,$allquestions)
    {
        #temporary variables for storing new quiz attributes
        $tempScore = 0;
        $tempTypes = [];
        $tempDiff = 0;
        $tempChapters = [];
        $tempDist = 0;
        $tempTime = 0;

        #compute the value of all new quiz attributes
        foreach($gene as $key => $value)
        {
            $tempScore += $allquestions[$value-1]->getScore(); #sum of new quiz score value
            $tempDiff += $allquestions[$value-1]->getDifficulty();
            $tempDist += $allquestions[$value-1]->getDistinguishingDegree();
            $tempTime += $allquestions[$value-1]->getSolutionTime(); #sum of new quiz time value
            /*
            #count the value of all question types in a quiz
            $s = $allquestions[$value-1]->getType();
            if (array_key_exists($s, $tempTypes)){
                $tempTypes[$s] += 1;
            } else {
                $tempTypes[$s] = 1;
            }

            #count the value of all chapter covered in a quiz
            $ss = $allquestions[$value-1]->getChapterCovered();
            if (array_key_exists($ss, $tempChapters)){
                $tempChapters[$ss] += 1;
            } else {
                $tempChapters[$ss] = 1;
            }*/
        }
        $tempDiff /= count($gene); #average quiz difficulty value
        $tempDist /= count($gene); #average quiz distinguishing degree value

        #computing normalized relative error (NRE) of the exam
        #NRE is the difference between expected value and real value
        $NRE = 0;
        $NRE += abs($allquestions->$sumScore - $tempScore)/ $allquestions->$sumScore;
        $NRE += abs($allquestions->$avgDiff - $tempDiff)/ $allquestions->$avgDiff;
        $NRE += abs($allquestions->$avgDist - $tempDist)/ $allquestions->$avgDist;
        $NRE += abs($allquestions->$sumTime - $tempTime)/ $allquestions->$sumTime;
        /*
        foreach($allquestions->$types as $key => $value)
        {
            if (!array_key_exists($key, $tempTypes)){
                $tempTypes[$key] = 0;
            }
            $NRE += abs($value - $tempTypes[$key])/ $value;
        }

        foreach($allquestions->$chapters as $key => $value)
        {
            if (!array_key_exists($key, $tempChapters)){
                $tempChapters[$key] = 0;
            }
            $NRE += abs($value - $tempChapters[$key])/ $value;
        }*/
        #print($NRE);
        #print("<br>");

        $fitness = 1/(1+$NRE);
        return $fitness;
    }

    /*
     * A convenience method for generating a random chromosome with a random
     * gene.
     */
    public static function genRandom($allquestions)
    {
        #get array of all question id
        $quizIds = array_map(function($o) { return $o->getId(); }, $allquestions); 

        #randomize the id by shuffling the array and take whatever several questions at the beginning of the array is as a quiz
        #this step is done like this so that no duplicate questions are in the quiz
        shuffle($quizIds);
        $newgene = array_slice($quizIds, 0, $allquestions->nquestion);

        #var_dump($newgene);
        $chromosome = new self($newgene,$allquestions);
        return $chromosome;
    }

    /*
     * return the fitness of the gene,
     * its uset to sort the population
     */
    public function __toString(){
        return (string)$this->fitness;
    }
    
}
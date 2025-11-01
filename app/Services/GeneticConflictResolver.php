<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class GeneticConflictResolver
{
    private int $populationSize = 20;
    private int $maxGenerations = 50;
    private float $mutationRate = 0.1;
    private float $crossoverRate = 0.8;
    private int $tournamentSize = 3;
    
    /**
     * Resolve conflicts using genetic algorithm approach
     */
    public function resolveConflicts(array $schedules, array $conflicts = null): array
    {
        Log::info("GeneticConflictResolver: Starting conflict resolution for " . count($schedules) . " schedules");
        
        // Generate initial population
        $population = $this->generateInitialPopulation($schedules);
        
        $bestSolution = $population[0];
        $bestFitness = $this->calculateFitness($bestSolution);
        
        Log::info("Initial best fitness: {$bestFitness}");
        
        // Evolution loop
        for ($generation = 0; $generation < $this->maxGenerations; $generation++) {
            // Evaluate fitness for all individuals
            $fitnessScores = [];
            foreach ($population as $individual) {
                $fitnessScores[] = $this->calculateFitness($individual);
            }
            
            // Find best individual in current generation
            $bestIndex = array_search(min($fitnessScores), $fitnessScores);
            $currentBest = $population[$bestIndex];
            $currentBestFitness = $fitnessScores[$bestIndex];
            
            if ($currentBestFitness < $bestFitness) {
                $bestFitness = $currentBestFitness;
                $bestSolution = $currentBest;
                Log::debug("Generation {$generation}: New best fitness = {$bestFitness}");
            }
            
            // Early termination if perfect solution found
            if ($bestFitness == 0) {
                Log::info("Perfect solution found at generation {$generation}");
                break;
            }
            
            // Create new generation
            $newPopulation = [$bestSolution]; // Keep best individual (elitism)
            
            while (count($newPopulation) < $this->populationSize) {
                // Tournament selection
                $parent1 = $this->tournamentSelection($population, $fitnessScores);
                $parent2 = $this->tournamentSelection($population, $fitnessScores);
                
                // Crossover
                if (rand(1, 100) / 100 < $this->crossoverRate) {
                    $children = $this->crossover($parent1, $parent2);
                    $newPopulation = array_merge($newPopulation, $children);
                } else {
                    $newPopulation[] = $parent1;
                    $newPopulation[] = $parent2;
                }
                
                // Mutation
                if (count($newPopulation) < $this->populationSize) {
                    $mutated = $this->mutate($parent1);
                    $newPopulation[] = $mutated;
                }
            }
            
            $population = array_slice($newPopulation, 0, $this->populationSize);
        }
        
        $finalConflicts = ConstraintScheduler::detectConflicts($bestSolution);
        Log::info("GeneticConflictResolver: Final solution has " . count($finalConflicts) . " conflicts");
        
        return $bestSolution;
    }

    /**
     * Identify critical pairs: course/instructor/year-level which MUST be paired (A & B)
     */
    private function identifyCriticalPairs(array $schedules): array
    {
        $pairMap = [];
        foreach ($schedules as $sched) {
            $key = ($sched['instructor'] ?? '?') . '|' . ($sched['course_code'] ?? $sched['courseCode'] ?? '?') . '|' . ($sched['year_level'] ?? $sched['yearLevel'] ?? '?');
            $block = $sched['block'] ?? '';
            if (!isset($pairMap[$key])) $pairMap[$key] = [];
            $pairMap[$key][] = $block;
        }
        $criticalPairs = [];
        foreach ($pairMap as $k => $blocks) {
            if (in_array('A', $blocks) && in_array('B', $blocks)) $criticalPairs[] = $k;
        }
        return $criticalPairs;
    }

    /**
     * Make sure paired blocks always move together for genetic ops.
     */
    private function bundlePairs(array $schedules, array $criticalPairs): array
    {
        $bundled = [];
        $seen = [];
        foreach ($schedules as $i => $sched) {
            $key = ($sched['instructor'] ?? '?') . '|' . ($sched['course_code'] ?? $sched['courseCode'] ?? '?') . '|' . ($sched['year_level'] ?? $sched['yearLevel'] ?? '?');
            $block = $sched['block'] ?? '';
            if (in_array($key, $criticalPairs)) {
                if (isset($seen[$key])) continue; // Already bundled
                // Bundle A&B together in 1 unit
                $paired = array_values(array_filter($schedules, function($c) use($key) {
                    $id = ($c['instructor'] ?? '?') . '|' . ($c['course_code'] ?? $c['courseCode'] ?? '?') . '|' . ($c['year_level'] ?? $c['yearLevel'] ?? '?');
                    $block = $c['block'] ?? '';
                    return $id === $key && in_array($block, ['A','B']);
                }));
                $bundled[] = $paired;
                $seen[$key] = true;
            } else {
                $bundled[] = [$sched];
            }
        }
        return $bundled;
    }

    private function flattenBundled(array $bundled): array
    {
        $flat = [];
        foreach ($bundled as $item) foreach ($item as $x) $flat[] = $x;
        return $flat;
    }

    /**
     * Generate initial population with random variations
     */
    private function generateInitialPopulation(array $schedules): array
    {
        $criticalPairs = $this->identifyCriticalPairs($schedules);
        $bundled = $this->bundlePairs($schedules, $criticalPairs);
        $population = [];
        for ($i = 0; $i < $this->populationSize; $i++) {
            shuffle($bundled); // Shuffle whole bundles (unit move)
            $individual = $this->flattenBundled($bundled);
            $population[] = $individual;
        }
        return $population;
    }

    /**
     * Calculate fitness score (lower is better)
     */
    private function calculateFitness(array $schedules): float
    {
        $conflicts = ConstraintScheduler::detectConflicts($schedules);
        $stats = ConstraintScheduler::getConflictStatistics($conflicts);
        $fitness = 0;
        $fitness += $stats['critical_count'] * 100;
        $fitness += $stats['high_count'] * 50;
        $fitness += $stats['medium_count'] * 10;
        $fitness += $stats['low_count'] * 1;
        // Strong penalty for missing a critical pair (if both are not scheduled together)
        $criticalPairs = $this->identifyCriticalPairs($schedules);
        foreach ($criticalPairs as $key) {
            $foundA = false; $foundB = false;
            foreach ($schedules as $sched) {
                $k = ($sched['instructor'] ?? '?') . '|' . ($sched['course_code'] ?? $sched['courseCode'] ?? '?') . '|' . ($sched['year_level'] ?? $sched['yearLevel'] ?? '?');
                $block = $sched['block'] ?? '';
                if ($k === $key && $block === 'A') $foundA = true;
                if ($k === $key && $block === 'B') $foundB = true;
            }
            if (!($foundA && $foundB)) $fitness += 200; // Strong penalty if missing A or B
        }
        // Add penalty for unscheduled courses
        $scheduledCount = count($schedules);
        $expectedCount = $this->getExpectedScheduleCount();
        if ($scheduledCount < $expectedCount) $fitness += ($expectedCount - $scheduledCount) * 20;
        // Penalty for overloads
        $overloads = $this->countOverloads($schedules);
        $fitness += $overloads * 40;
        return $fitness;
    }

    /**
     * Tournament selection for choosing parents
     */
    private function tournamentSelection(array $population, array $fitnessScores): array
    {
        $tournament = [];
        $tournamentFitness = [];
        
        for ($i = 0; $i < $this->tournamentSize; $i++) {
            $index = array_rand($population);
            $tournament[] = $population[$index];
            $tournamentFitness[] = $fitnessScores[$index];
        }
        
        $bestIndex = array_search(min($tournamentFitness), $tournamentFitness);
        return $tournament[$bestIndex];
    }

    /**
     * Crossover operation between two parents
     */
    private function crossover(array $parent1, array $parent2): array
    {
        $criticalPairs = $this->identifyCriticalPairs($parent1);
        $bundled1 = $this->bundlePairs($parent1, $criticalPairs);
        $bundled2 = $this->bundlePairs($parent2, $criticalPairs);
        $len = min(count($bundled1), count($bundled2));
        $point = rand(1, $len-1);
        $child1 = array_merge(array_slice($bundled1, 0, $point), array_slice($bundled2, $point));
        $child2 = array_merge(array_slice($bundled2, 0, $point), array_slice($bundled1, $point));
        return [ $this->flattenBundled($child1), $this->flattenBundled($child2) ];
    }

    /**
     * Mutation operation
     */
    private function mutate(array $individual): array
    {
        $criticalPairs = $this->identifyCriticalPairs($individual);
        $bundled = $this->bundlePairs($individual, $criticalPairs);
        $mutationIndex = array_rand($bundled);
        // Mutate all elements in the bundle
        foreach ($bundled[$mutationIndex] as &$sched) {
            $sched = $this->mutateTimeSlot($sched);
        }
        unset($sched);
        return $this->flattenBundled($bundled);
    }

    /**
     * Mutate schedules by creating random variations
     */
    private function mutateSchedules(array $schedules): array
    {
        $mutated = [];
        
        foreach ($schedules as $schedule) {
            // Randomly decide whether to mutate this schedule
            if (rand(1, 100) / 100 < 0.3) { // 30% mutation rate for initial population
                $mutated[] = $this->mutateTimeSlot($schedule);
            } else {
                $mutated[] = $schedule;
            }
        }
        
        return $mutated;
    }

    /**
     * Mutate time slot of a schedule
     */
    private function mutateTimeSlot(array $schedule): array
    {
        $timeSlots = TimeScheduler::generateComprehensiveTimeSlots();
        $randomSlot = $timeSlots[array_rand($timeSlots)];
        
        $mutated = $schedule;
        $mutated['day'] = $randomSlot['day'];
        $mutated['start_time'] = $randomSlot['start'];
        $mutated['end_time'] = $randomSlot['end'];
        
        return $mutated;
    }

    /**
     * Mutate room of a schedule
     */
    private function mutateRoom(array $schedule): array
    {
        // This would need access to available rooms
        // For now, just return the original schedule
        return $schedule;
    }

    /**
     * Mutate day of a schedule
     */
    private function mutateDay(array $schedule): array
    {
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $randomDay = $days[array_rand($days)];
        
        $mutated = $schedule;
        $mutated['day'] = $randomDay;
        
        return $mutated;
    }

    /**
     * Get expected number of schedules (placeholder)
     */
    private function getExpectedScheduleCount(): int
    {
        // This should be determined based on the input data
        return 50; // Placeholder value
    }

    /**
     * Helper: count overloads for part-time, pairs can break soft limit (for reporting/penalty, not to block)
     */
    private function countOverloads(array $schedules): int
    {
        $maxCourses = 3;
        $byInstructor = [];
        foreach ($schedules as $sched) {
            $emp = ($sched['employment_type'] ?? $sched['employmentType'] ?? 'FULL-TIME');
            $inst = $sched['instructor'] ?? $sched['name'] ?? '?';
            if ($emp === 'PART-TIME') {
                if (!isset($byInstructor[$inst])) $byInstructor[$inst]=0;
                $byInstructor[$inst]++;
            }
        }
        $over = 0;
        foreach ($byInstructor as $inst=>$n) {
            if ($n > $maxCourses) $over++;
        }
        return $over;
    }

    /**
     * Set genetic algorithm parameters
     */
    public function setParameters(array $parameters): void
    {
        if (isset($parameters['population_size'])) {
            $this->populationSize = max(10, min(100, $parameters['population_size']));
        }
        
        if (isset($parameters['max_generations'])) {
            $this->maxGenerations = max(10, min(200, $parameters['max_generations']));
        }
        
        if (isset($parameters['mutation_rate'])) {
            $this->mutationRate = max(0.01, min(0.5, $parameters['mutation_rate']));
        }
        
        if (isset($parameters['crossover_rate'])) {
            $this->crossoverRate = max(0.1, min(1.0, $parameters['crossover_rate']));
        }
        
        Log::debug("GeneticConflictResolver parameters set: population={$this->populationSize}, " .
                  "generations={$this->maxGenerations}, mutation={$this->mutationRate}, crossover={$this->crossoverRate}");
    }

    /**
     * Get algorithm statistics
     */
    public function getStatistics(): array
    {
        return [
            'population_size' => $this->populationSize,
            'max_generations' => $this->maxGenerations,
            'mutation_rate' => $this->mutationRate,
            'crossover_rate' => $this->crossoverRate,
            'tournament_size' => $this->tournamentSize
        ];
    }
}

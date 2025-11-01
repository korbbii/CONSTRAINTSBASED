#!/usr/bin/env python3
import json
import sys
from ortools.sat.python import cp_model

print("Testing OR-Tools directly...", file=sys.stderr)

# Create a simple model
model = cp_model.CpModel()
solver = cp_model.CpSolver()

# Add a simple variable
x = model.NewIntVar(0, 10, 'x')
model.Add(x >= 5)

# Solve
status = solver.Solve(model)

if status == cp_model.OPTIMAL:
    print("OR-Tools working correctly", file=sys.stderr)
    result = {"success": True, "message": "OR-Tools test successful", "value": solver.Value(x)}
else:
    print("OR-Tools failed", file=sys.stderr)
    result = {"success": False, "message": "OR-Tools test failed"}

print(json.dumps(result))

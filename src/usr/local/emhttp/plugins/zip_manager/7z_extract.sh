#!/bin/bash

input="$(basename -- "$1")"
output="$(basename -- "$2")"

inputPath="/mnt/user/$input"
outputPath="/mnt/user/$output"

if [[ -f "$inputPath" && -d "$outputPath" ]]; then
  /usr/bin/7zzs x "$inputPath" -o"$outputPath"
  echo "✅ Extraction complete"
else
  echo "❌ Error: Invalid paths"
fi

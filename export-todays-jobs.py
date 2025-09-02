#!/usr/bin/env python3
"""
Export today's jobs from HandyManager database to CSV.

This script exports jobs that started today, sorted with:
1. In-progress jobs (no closed_at) first
2. Completed jobs sorted by end date (ascending)

Usage:
    python export-todays-jobs.py <database_file> <output_file>
"""

import sys
import sqlite3
import csv
from datetime import datetime
import argparse


def format_datetime_without_seconds_or_year(datetime_str):
    """Format datetime string without seconds and year."""
    if not datetime_str:
        return ''
    
    # Parse the datetime string
    dt = datetime.fromisoformat(datetime_str.replace('Z', '+00:00'))
    
    # Format as MM/DD HH:MM (24-hour format)
    return dt.strftime('%m/%d %H:%M')

def export_todays_jobs(db_file, output_file):
    """Export today's jobs to CSV file."""
    try:
        # Connect to database
        conn = sqlite3.connect(db_file)
        conn.row_factory = sqlite3.Row  # Enable column access by name
        cursor = conn.cursor()
        
        # Get today's date in YYYY-MM-DD format
        today = datetime.now().strftime('%Y-%m-%d')
        
        # Query for jobs that started today
        query = """
            SELECT 
                start_time,
                end_time,
                tech_name,
                location,
                notes,
                closed_at
            FROM jobs 
            WHERE DATE(start_time) = ?
            ORDER BY 
                CASE WHEN closed_at IS NULL THEN 0 ELSE 1 END,
                COALESCE(end_time, '9999-12-31') ASC
        """
        
        cursor.execute(query, (today,))
        jobs = cursor.fetchall()
        
        # Write to CSV
        with open(output_file, 'w', newline='', encoding='utf-8') as csvfile:
            fieldnames = ['Start Time', 'End Time', 'Tech Name', 'Location', 'Notes', 'Status']
            writer = csv.DictWriter(csvfile, fieldnames=fieldnames)
            
            # Write header
            writer.writeheader()
            
            # Write job data
            for job in jobs:
                status = 'In Progress' if job['closed_at'] is None else 'Completed'
                writer.writerow({
                    'Start Time': format_datetime_without_seconds_or_year(job['start_time']),
                    'End Time': format_datetime_without_seconds_or_year(job['end_time']) if job['end_time'] else '',
                    'Tech Name': job['tech_name'],
                    'Location': job['location'],
                    'Notes': job['notes'] or '',
                    'Status': status
                })
        
        print(f"Successfully exported {len(jobs)} jobs to {output_file}")
        conn.close()
        return True
        
    except sqlite3.Error as e:
        print(f"Database error: {e}")
        return False
    except Exception as e:
        print(f"Error: {e}")
        return False

def main():
    """Main function to parse arguments and run export."""
    parser = argparse.ArgumentParser(description='Export today\'s jobs from HandyManager database to CSV')
    parser.add_argument('database', help='SQLite database file')
    parser.add_argument('output', help='Output CSV file')
    
    # If no arguments provided, show help
    if len(sys.argv) == 1:
        parser.print_help()
        sys.exit(1)
    
    args = parser.parse_args()
    
    if export_todays_jobs(args.database, args.output):
        sys.exit(0)
    else:
        sys.exit(1)

if __name__ == "__main__":
    main()
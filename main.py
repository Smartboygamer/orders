import subprocess
import time

def run_php_script(script_name):
    try:
        # Run the PHP script and capture output
        process = subprocess.Popen(
            ["php", script_name],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE
        )
        stdout, stderr = process.communicate()

        if stdout:
            print(f"Output of {script_name}:\n{stdout.decode()}")
        if stderr:
            print(f"Error in {script_name}:\n{stderr.decode()}")
    except Exception as e:
        print(f"Failed to run {script_name}: {e}")

if __name__ == "__main__":
    while True:
        print("Running app.php...")
        run_php_script("app.php")
        
        print("Running status.php...")
        run_php_script("status.php")
        
        # Wait before running again (adjust seconds as needed)
        time.sleep(10)

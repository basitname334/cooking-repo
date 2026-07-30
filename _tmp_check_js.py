import re
from pathlib import Path
import subprocess

t = Path(r"D:\New folder\cooking-repo\admin\orders.php").read_text(encoding="utf-8")
s = re.findall(r"<script[^>]*>(.*?)</script>", t, re.S | re.I)[0]
s2 = re.sub(r"<\?php echo json_encode\([^;]+;\s*\?>", "[]", s)
s2 = re.sub(r"<\?php\s+echo\s+addslashes\(.*?\)\s*;\s*\?>", '"x"', s2, flags=re.S)
s2 = re.sub(r"<\?php\s+echo\s+.*?\s*;\s*\?>", '"x"', s2, flags=re.S)
s2 = re.sub(r"<\?php\s+e\([^)]*\);\s*\?>", '"x"', s2, flags=re.S)
s2 = re.sub(r"<\?=.*?\?>", '"x"', s2, flags=re.S)
s2 = re.sub(r"<\?php.*?\?>", " null ", s2, flags=re.S)
out = Path(r"D:\New folder\cooking-repo\_tmp_orders.js")
out.write_text(s2, encoding="utf-8")
print("php left", len(re.findall(r"<\?", s2)))
r = subprocess.run(
    [r"C:\Program Files\nodejs\node.exe", "--check", str(out)],
    capture_output=True,
    text=True,
)
print(r.stderr or r.stdout or "OK")
print("exit", r.returncode)
if r.returncode != 0:
    # show context around error line if present
    m = re.search(r":(\d+)\n", r.stderr or "")
    if m:
        ln = int(m.group(1))
        lines = s2.splitlines()
        for i in range(max(0, ln - 3), min(len(lines), ln + 3)):
            print(f"{i+1}: {lines[i]}")
out.unlink(missing_ok=True)

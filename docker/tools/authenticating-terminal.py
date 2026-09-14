"""Exercise the actual bin/dev wrapper with an ordinary Linux terminal and pipe.

Only disposable consumer accounts are used. Passwords exist in process memory and
stdin, never argv, terminal transcripts, environment variables or evidence files.
"""

import errno
import json
import os
import pty
import secrets
import select
import signal
import subprocess
import sys
import termios
import time
import uuid


def cleanup_terminal(pid, grace=0.5, reap_timeout=1):
    # The caller observes exit with WNOWAIT, retaining the child PID until all
    # group signals are sent. This prevents signalling a recycled process group.
    reaped = False
    if pid == os.getpgrp() or os.getpgid(pid) != pid or os.getsid(pid) != pid:
        raise RuntimeError("Terminal child does not own its expected process group.")
    try:
        os.killpg(pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    deadline = time.monotonic() + grace
    while time.monotonic() < deadline:
        time.sleep(min(0.02, max(0, deadline - time.monotonic())))
    try:
        os.killpg(pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    deadline = time.monotonic() + reap_timeout
    while time.monotonic() < deadline:
        done, _ = os.waitpid(pid, os.WNOHANG)
        if done == pid:
            reaped = True
            break
        time.sleep(0.01)
    if not reaped:
        raise RuntimeError("Terminal child cleanup exceeded its bounded reap deadline.")


def terminal(command, password, confirmation, timeout=45):
    pid, master = pty.fork()
    if pid == 0:
        try:
            os.execv(command[0], command)
        except Exception:
            os._exit(127)
    transcript = b""
    prompt_offset = 0
    sent = 0
    deadline = time.monotonic() + timeout
    try:
        while time.monotonic() < deadline:
            ready, _, _ = select.select([master], [], [], 0.05)
            eof = False
            if ready:
                try:
                    data = os.read(master, 8192)
                except OSError as error:
                    if error.errno != errno.EIO:
                        raise
                    data = b""
                transcript += data
                eof = not data
            if password.encode() in transcript or confirmation.encode() in transcript:
                raise RuntimeError("Hidden terminal input was echoed.")
            if len(transcript) > 1024 * 1024:
                raise RuntimeError("Terminal output exceeded its verification limit.")
            prompt = b"Password:" if sent == 0 else b"Confirm password:"
            if sent < 2 and prompt.lower() in transcript[prompt_offset:].lower():
                # The prompt can be flushed just before Symfony disables echo.
                time.sleep(0.15)
                if termios.tcgetattr(master)[3] & termios.ECHO:
                    raise RuntimeError("Terminal input echo was not disabled.")
                os.write(master, (password if sent == 0 else confirmation).encode() + b"\n")
                sent += 1
                prompt_offset = len(transcript)
            # Do not return at child exit: drain the PTY to EOF and check every
            # byte, including output buffered just before exit. WNOWAIT reserves
            # the group leader's PID until cleanup has signalled its descendants.
            status = os.waitid(os.P_PID, pid, os.WEXITED | os.WNOHANG | os.WNOWAIT)
            if status is not None and eof:
                code = status.si_status if status.si_code == os.CLD_EXITED else -status.si_status
                invalid_input = b"Invalid account input." in transcript
                provisioning_failure = b"Account provisioning failed." in transcript
                print(
                    f"Terminal attempt exit={code}; hidden inputs sent={sent}; "
                    f"invalid-input diagnostic={invalid_input}; provisioning-failure diagnostic={provisioning_failure}."
                )
                return code, transcript
        raise RuntimeError("Hidden terminal provisioning timed out.")
    finally:
        try:
            cleanup_terminal(pid)
        finally:
            os.close(master)


def verify(container, email, password):
    result = subprocess.run(
        ["docker", "exec", "-i", container, "php", "docker/tools/consumer-authenticating.php", "verify-password"],
        input=json.dumps({"email": email, "password": password}).encode(),
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        timeout=30,
    )
    if result.returncode:
        raise RuntimeError("Provisioned credential verification failed.")


def main():
    root, container = sys.argv[1:]
    wrapper = os.path.join(root, "bin/dev")
    password = "  auth-canary-" + secrets.token_hex(20) + "  "
    email = "terminal-" + secrets.token_hex(8) + "@example.test"
    command = [wrapper, "console", "app:account:provision", email]
    code, output = terminal(command, password, password)
    if code != 0:
        raise RuntimeError("Hidden terminal provisioning failed.")
    if not any(is_uuid(line.strip()) for line in output.decode(errors="replace").splitlines()):
        raise RuntimeError("Hidden terminal success did not print an account UUID.")
    verify(container, email, password)
    code, _ = terminal([*command[:-1], "mismatch-" + email], password, "auth-canary-" + secrets.token_hex(20))
    if code != 2:
        raise RuntimeError("Hidden confirmation mismatch was not rejected.")
    email = "pipe-" + secrets.token_hex(8) + "@example.test"
    result = subprocess.run(
        [wrapper, "console", "app:account:provision", email, "--password-stdin", "--no-interaction"],
        input=(password + "\r\n").encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=45,
    )
    if result.returncode or password.encode() in result.stdout + result.stderr or not is_uuid(result.stdout.strip().decode()):
        raise RuntimeError("Actual wrapper pipe provisioning failed.")
    verify(container, email, password)
    print("Verified bin/dev hidden terminal confirmation, no echo, mismatch rejection, explicit pipe and preserved spaces.")


def is_uuid(value):
    try:
        uuid.UUID(value)
        return True
    except ValueError:
        return False


def self_test():
    # Linux subreaping lets this disposable test collect the forked descendant
    # itself and prove it was killed, without relying on the host's PID 1.
    import ctypes

    libc = ctypes.CDLL(None, use_errno=True)
    if libc.prctl(36, 1, 0, 0, 0) != 0:  # PR_SET_CHILD_SUBREAPER
        raise RuntimeError("Cannot enable disposable terminal self-test subreaping.")
    read_fd, write_fd = os.pipe()
    os.set_inheritable(write_fd, True)
    stubborn = """
import os, signal, sys, time
signal.signal(signal.SIGTERM, signal.SIG_IGN)
signal.signal(signal.SIGHUP, signal.SIG_IGN)
descendant = os.fork()
if descendant:
    os.write(int(sys.argv[1]), f"{os.getpid()} {descendant}".encode())
os.close(int(sys.argv[1]))
while True:
    time.sleep(1)
"""
    password = "  auth-canary-" + secrets.token_hex(20) + "  "
    confirmation = "auth-canary-" + secrets.token_hex(20)
    started = time.monotonic()
    timed_out = False
    try:
        try:
            terminal([sys.executable, "-c", stubborn, str(write_fd)], password, confirmation, timeout=0.8)
        except RuntimeError as error:
            if str(error) != "Hidden terminal provisioning timed out.":
                raise
            timed_out = True
    finally:
        os.close(write_fd)
        try:
            ready, _, _ = select.select([read_fd], [], [], 1)
            if not ready:
                raise RuntimeError("Disposable terminal descendants did not report readiness.")
            identifiers = [int(value) for value in os.read(read_fd, 128).split()]
        finally:
            os.close(read_fd)
        if len(identifiers) != 2:
            raise RuntimeError("Disposable terminal descendant identities were unavailable.")
        # Also bound the self-test's failure cleanup. WNOWAIT retains each child
        # identity while signalling, so a recycled PID cannot be targeted.
        descendant_killed = False
        leader_reaped = False
        cleanup_intervened = False
        deadline = time.monotonic() + 1
        pending = set(identifiers)
        while pending and time.monotonic() < deadline:
            for child in tuple(pending):
                try:
                    status = os.waitid(os.P_PID, child, os.WEXITED | os.WNOHANG | os.WNOWAIT)
                except ChildProcessError:
                    if child == identifiers[0]:
                        leader_reaped = True
                    pending.remove(child)
                    continue
                if status is not None:
                    if child == identifiers[1]:
                        descendant_killed = status.si_code == os.CLD_KILLED and status.si_status == signal.SIGKILL
                    os.waitpid(child, os.WNOHANG)
                    pending.remove(child)
                else:
                    # A live adopted child would be a cleanup failure, but must
                    # still be removed by the self-test before reporting it.
                    os.kill(child, signal.SIGKILL)
                    cleanup_intervened = True
            time.sleep(0.01)
        if pending:
            raise RuntimeError("Disposable terminal self-test cleanup did not complete.")
    if not timed_out or not leader_reaped or not descendant_killed or cleanup_intervened or time.monotonic() - started >= 4:
        raise RuntimeError("Bounded terminal process-group cleanup self-test failed.")
    print("Verified bounded timeout cleanup: TERM-ignoring PTY leader and forked descendant killed; both reaped.")

    dialogue = """
import os, sys, termios
settings = termios.tcgetattr(0)
settings[3] &= ~termios.ECHO
termios.tcsetattr(0, termios.TCSANOW, settings)
os.write(1, b"Password:")
password = sys.stdin.buffer.readline().rstrip(b"\\n")
os.write(1, b"\\nConfirm password:")
confirmation = sys.stdin.buffer.readline().rstrip(b"\\n")
os.write(1, b"\\n" + b"x" * 20000 + b"\\n")
if sys.argv[1] != "clean":
    secret = password if sys.argv[1] == "password" else confirmation
    os.write(1, secret[:len(secret)//2])
    os.write(1, secret[len(secret)//2:])
os.write(1, b"\\nterminal-self-test-complete\\n")
"""
    for mode in ("clean", "password", "confirmation"):
        try:
            code, output = terminal([sys.executable, "-c", dialogue, mode], password, confirmation, timeout=5)
        except RuntimeError as error:
            if mode == "clean" or str(error) != "Hidden terminal input was echoed.":
                raise
        else:
            if mode != "clean" or code != 0 or not output.endswith(b"terminal-self-test-complete\r\n"):
                raise RuntimeError("Terminal final-output canary self-test failed.")
    print("Verified complete multi-read PTY output through EOF and rejection of final password/confirmation echoes; no secret output published.")


if __name__ == "__main__":
    try:
        if sys.argv[1:] == ["--self-test"]:
            self_test()
        else:
            main()
    except RuntimeError as error:
        # Every RuntimeError above has a fixed, credential-free diagnostic.
        print(str(error), file=sys.stderr)
        sys.exit(1)
    except Exception:
        # No subprocess output, locals or password-containing traceback on failure.
        print("Authentication terminal/pipe verification failed.", file=sys.stderr)
        sys.exit(1)

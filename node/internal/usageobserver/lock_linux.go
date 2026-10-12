//go:build linux

package usageobserver

import (
	"golang.org/x/sys/unix"
	"os"
	"path/filepath"
)

func lockDirectory(dir string) (*os.File, error) {
	f, err := os.OpenFile(filepath.Join(dir, ".observer.lock"), os.O_CREATE|os.O_RDWR|unix.O_NOFOLLOW, 0600)
	if err != nil {
		return nil, err
	}
	if err := unix.Flock(int(f.Fd()), unix.LOCK_EX|unix.LOCK_NB); err != nil {
		_ = f.Close()
		return nil, err
	}
	return f, nil
}

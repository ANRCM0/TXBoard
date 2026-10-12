//go:build linux

package usagebucket

import (
	"os"
	"path/filepath"

	"golang.org/x/sys/unix"
)

func lockStore(dir string) (*os.File, error) {
	f, err := openStoreFile(filepath.Join(dir, ".lock"), os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return nil, err
	}
	if err := unix.Flock(int(f.Fd()), unix.LOCK_EX|unix.LOCK_NB); err != nil {
		_ = f.Close()
		return nil, err
	}
	return f, nil
}

func openStoreFile(path string, flags int, mode os.FileMode) (*os.File, error) {
	return os.OpenFile(path, flags|unix.O_NOFOLLOW, mode)
}

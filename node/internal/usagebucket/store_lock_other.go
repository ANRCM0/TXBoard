//go:build !linux

package usagebucket

import "os"

func lockStore(string) (*os.File, error) { return nil, ErrStorePlatform }
func openStoreFile(string, int, os.FileMode) (*os.File, error) {
	return nil, ErrStorePlatform
}

//go:build !linux

package usagev2

import (
	"errors"
	"os"
)

func lockDirectory(string) (*os.File, error) {
	return nil, errors.New("usage v2 durable spool requires verified Linux file and directory fsync")
}

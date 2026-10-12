//go:build !linux

package usageobserver

import (
	"errors"
	"os"
)

func lockDirectory(string) (*os.File, error) {
	return nil, errors.New("usage observer requires Linux directory durability and exclusive locking")
}

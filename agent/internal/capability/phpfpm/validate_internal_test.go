package phpfpm

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestTheSyntheticConfigWritesItsErrorLogToTheScratchDirectory(t *testing.T) {
	base := strings.Split("[global]\n;error_log = /var/log/commented.log\npid = /run/php/php8.3-fpm.pid\nerror_log = /var/log/php8.3-fpm.log\ninclude=/etc/php/8.3/fpm/pool.d/*.conf", "\n")

	got := redirectGlobalLog(base, "/tmp/scratch/php-fpm.log")

	want := "[global]\n;error_log = /var/log/commented.log\npid = /run/php/php8.3-fpm.pid\nerror_log = /tmp/scratch/php-fpm.log\ninclude=/etc/php/8.3/fpm/pool.d/*.conf"
	if strings.Join(got, "\n") != want {
		t.Errorf("unexpected configuration:\n%s", strings.Join(got, "\n"))
	}

	if strings.Join(base, "\n") == strings.Join(got, "\n") {
		t.Errorf("the input must not be returned unchanged")
	}
}

func TestDeletingTheOnlyLivePoolStillLeavesTheSyntheticConfigAPool(t *testing.T) {
	base := t.TempDir()
	poolDir := filepath.Join(base, "8.3", "fpm", "pool.d")

	if err := os.MkdirAll(poolDir, 0o755); err != nil {
		t.Fatal(err)
	}

	resourceID := "d9aff091-25b8-40da-b1ae-ec1d9b191680"

	if err := os.WriteFile(filepath.Join(poolDir, resourceID+".conf"), []byte("[x]\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(filepath.Join(base, "8.3", "fpm", "php-fpm.conf"), []byte("[global]\ninclude="+poolDir+"/*.conf\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	capability := &PhpFpmCapability{cfg: Config{PoolBaseDir: base}}

	conf, cleanup, err := capability.buildSyntheticConfig("8.3", resourceID, "")
	if err != nil {
		t.Fatal(err)
	}

	defer cleanup()

	pools, _ := filepath.Glob(filepath.Join(filepath.Dir(conf), "pool.d", "*.conf"))
	if len(pools) != 1 || !strings.HasSuffix(pools[0], "lesta-validate-placeholder.conf") {
		t.Errorf("removing the only pool must leave the placeholder, got %v", pools)
	}
}
